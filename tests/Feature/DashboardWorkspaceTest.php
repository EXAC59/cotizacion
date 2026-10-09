<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class DashboardWorkspaceTest extends AuthenticatedFeatureTestCase
{
    private function quote(string $folio, string $status = 'en_elaboracion', ?User $owner = null): Quote
    {
        $client = Client::firstOrCreate(['company' => 'Workspace Client'], ['rfc' => 'WKS010101ABC']);

        return Quote::create(['folio' => $folio, 'client_id' => $client->id,
            'created_by' => ($owner ?? auth()->user())->id, 'status' => $status,
            'tax_percent' => 16, 'total' => 100]);
    }

    #[Test]
    public function counts_cover_all_pages_and_ready_means_finished_without_actual_delivery(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->quote('COT-WKS-'.$i);
        }
        $this->quote('COT-READY', 'pendiente_envio');
        $this->quote('COT-SENT', 'pendiente_envio')->update(['sent_at' => now()]);
        $this->quote('COT-MOD', 'modificacion')->update(['sent_at' => now()]);

        $response = $this->getJson('/api/dashboard/quotes')->assertOk();
        $response->assertJsonPath('counts.all', 29)->assertJsonPath('counts.pending', 27)
            ->assertJsonPath('counts.progress', 26)->assertJsonPath('counts.ready', 1)
            ->assertJsonPath('counts.sent', 2)->assertJsonPath('meta.total', 29)
            ->assertJsonCount(15, 'data');
        $this->getJson('/api/dashboard/quotes?focus=ready')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.folio', 'COT-READY');
    }

    #[Test]
    public function reading_is_personal_idempotent_and_does_not_change_quote_or_lock(): void
    {
        $quote = $this->quote('COT-PERSONAL');
        $quote->update(['locked_by' => auth()->id(), 'locked_at' => now()->subHour()]);
        $before = $quote->fresh()->getAttributes();
        $this->getJson('/api/dashboard/quotes?focus=unread')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($before, $quote->fresh()->getAttributes());
        $this->postJson("/api/cotizaciones/{$quote->id}/viewed")->assertOk();
        $first = DB::table('quote_user_views')->first()->first_opened_at;
        $this->travel(5)->minutes();
        $this->postJson("/api/cotizaciones/{$quote->id}/viewed")->assertOk();
        $this->assertDatabaseCount('quote_user_views', 1);
        $this->assertSame($first, DB::table('quote_user_views')->first()->first_opened_at);
        $this->assertSame($before, $quote->fresh()->getAttributes());
        $this->getJson('/api/dashboard/quotes?focus=unread')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson('/api/dashboard/quotes?focus=unread')->assertOk()->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function filters_combine_scope_dates_search_status_and_focus(): void
    {
        $sales = $this->demoUser('ventas');
        $this->quote('COT-FILTER-MINE', 'pendiente_envio', $sales)->forceFill(['created_at' => '2026-09-20 12:00:00'])->save();
        $this->quote('COT-FILTER-TEAM', 'pendiente_envio')->forceFill(['created_at' => '2026-09-20 12:00:00'])->save();
        $this->quote('COT-OLD', 'pendiente_envio', $sales)->forceFill(['created_at' => '2026-08-20 12:00:00'])->save();
        $this->actingAs($sales);
        $this->getJson('/api/dashboard/quotes?scope=mine&from=2026-09-01&to=2026-09-30&search=FILTER&status=pendiente_envio&focus=ready')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.folio', 'COT-FILTER-MINE')
            ->assertJsonPath('counts.all', 1);
        $this->getJson('/api/dashboard/quotes?scope=all&from=2026-09-01&to=2026-09-30&search=FILTER')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function invalid_dates_status_focus_and_pages_are_rejected(): void
    {
        foreach (['from=2026-09-30&to=2026-09-01', 'focus=invalid', 'status=invalid', 'page=0'] as $query) {
            $this->getJson('/api/dashboard/quotes?'.$query)->assertUnprocessable();
        }
    }

    #[Test]
    public function unauthenticated_reads_and_views_are_rejected(): void
    {
        $quote = $this->quote('COT-AUTH');
        auth()->forgetGuards();
        $this->getJson('/api/dashboard/quotes')->assertUnauthorized();
        $this->postJson("/api/cotizaciones/{$quote->id}/viewed")->assertUnauthorized();
        $this->assertDatabaseCount('quote_user_views', 0);
    }

    #[Test]
    public function missing_quote_cannot_be_marked_as_read_and_lists_do_not_create_reads(): void
    {
        $this->quote('COT-UNREAD');
        $this->getJson('/api/dashboard/quotes')->assertOk();
        $this->getJson('/api/cotizaciones')->assertOk();
        $this->assertDatabaseCount('quote_user_views', 0);
        $this->postJson('/api/cotizaciones/00000000-0000-0000-0000-000000000000/viewed')->assertNotFound();
        $this->assertDatabaseCount('quote_user_views', 0);
    }

    #[Test]
    public function server_checks_quote_permissions_for_counts_and_read_tracking(): void
    {
        $quote = $this->quote('COT-PERMISSION');
        $sales = $this->demoUser('ventas');
        $rbac = app(\App\Services\Rbac\RbacService::class);
        $permissions = $rbac->getRolePermissionMap();
        $permissions['ventas']['cotizaciones'] = [];
        $rbac->updateRolePermissionMap($permissions);
        $this->actingAs($sales);
        $this->getJson('/api/dashboard/quotes')->assertForbidden();
        $this->postJson("/api/cotizaciones/{$quote->id}/viewed")->assertForbidden();
        $this->assertDatabaseCount('quote_user_views', 0);
    }

    #[Test]
    public function pagination_is_stable_and_status_sorting_happens_before_pagination(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->quote('COT-PAGE-'.$i, $i < 5 ? 'solicitud_cotizaciones' : 'en_elaboracion');
        }
        $first = $this->getJson('/api/dashboard/quotes?sort=status')->assertOk();
        $second = $this->getJson('/api/dashboard/quotes?sort=status&page=2')->assertOk();
        $first->assertJsonPath('data.0.status', 'solicitud_cotizaciones')->assertJsonCount(15, 'data');
        $second->assertJsonPath('meta.total', 21)->assertJsonCount(6, 'data');
        $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
        $this->assertCount(21, array_unique($ids));
    }

    #[Test]
    public function recent_quotes_are_the_ten_newest_from_a_rolling_seven_day_window(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-29 12:00:00'));
        $sales = $this->demoUser('ventas');
        for ($i = 0; $i < 12; $i++) {
            $this->quote('COT-RECENT-'.$i, 'en_elaboracion', $sales)
                ->forceFill(['created_at' => now()->subHours($i)])->save();
        }
        $this->quote('COT-RECENT-OLD')->forceFill(['created_at' => now()->subDays(7)->subSecond()])->save();
        $this->quote('COT-RECENT-BOUNDARY')->forceFill(['created_at' => now()->subDays(7)])->save();
        $response = $this->getJson('/api/dashboard/quotes?scope=all&recent=1')->assertOk();
        $response->assertJsonCount(10, 'data')->assertJsonPath('meta.perPage', 10)
            ->assertJsonPath('meta.total', 13)->assertJsonPath('data.0.folio', 'COT-RECENT-0')
            ->assertJsonPath('data.9.folio', 'COT-RECENT-9');
        $this->travel(1)->seconds();
        $this->getJson('/api/dashboard/quotes?scope=all&recent=1')->assertOk()
            ->assertJsonPath('meta.total', 12);
        $this->travelBack();
    }

    #[Test]
    public function pending_table_paginates_and_filters_workflow_steps_without_sent_quotes(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->quote('COT-STEP-'.$i, 'solicitud_cotizaciones');
        }
        $this->quote('COT-STEP-PROGRESS');
        $this->quote('COT-STEP-READY', 'pendiente_envio');
        $this->quote('COT-STEP-SENT', 'pendiente_envio')->update(['sent_at' => now()]);
        $this->getJson('/api/dashboard/quotes?focus=pending&per_page=5&sort=status')->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 8)
            ->assertJsonPath('meta.lastPage', 2)->assertJsonPath('data.0.status', 'solicitud_cotizaciones');
        $this->getJson('/api/dashboard/quotes?focus=pending&status=pendiente_envio&per_page=5')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.folio', 'COT-STEP-READY');
        $this->getJson('/api/dashboard/quotes?per_page=500')->assertUnprocessable();
        $this->getJson('/api/dashboard/quotes?recent=invalid')->assertUnprocessable();
    }

    #[Test]
    public function sales_can_list_pending_requests_and_progress_when_no_quotes_are_ready_to_send(): void
    {
        $seller = $this->demoUser('ventas');
        $this->quote('COT-SALES-REQUEST', 'solicitud_cotizaciones', $seller)
            ->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->quote('COT-SALES-PROGRESS', 'en_elaboracion', $seller)
            ->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->quote('COT-SALES-DELIVERED', 'pendiente_envio', $seller)->update(['sent_at' => now()]);
        $this->quote('COT-SALES-ACCEPTED', 'aceptada', $seller);
        $this->actingAs($seller);

        $this->getJson('/api/dashboard/quotes?scope=all&focus=pending&sort=oldest&per_page=5')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('counts.ready', 0)
            ->assertJsonPath('data.0.folio', 'COT-SALES-REQUEST')
            ->assertJsonPath('data.1.folio', 'COT-SALES-PROGRESS');
        $this->getJson('/api/dashboard/quotes?scope=all&focus=pending&status=en_elaboracion&sort=oldest&per_page=5')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.folio', 'COT-SALES-PROGRESS');
        $this->getJson('/api/dashboard/quotes?scope=all&focus=pending&status=pendiente_envio&per_page=5')->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->quote('COT-SALES-READY', 'pendiente_envio', $seller);
        $this->getJson('/api/dashboard/quotes?scope=all&focus=pending&status=pendiente_envio&per_page=5')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.folio', 'COT-SALES-READY');
    }


    #[Test]
    public function pending_quotes_show_newest_creation_first_across_pages(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->quote('COT-NEWEST-'.$i)
                ->forceFill(['created_at' => now()->subDays($i), 'updated_at' => now()->addDays($i)])->save();
        }
        $this->quote('COT-NEWEST-SENT', 'pendiente_envio')->update(['sent_at' => now()]);
        $first = $this->getJson('/api/dashboard/quotes?focus=pending&sort=date&per_page=5')
            ->assertOk()->assertJsonPath('meta.total', 9)->assertJsonCount(5, 'data');
        $second = $this->getJson('/api/dashboard/quotes?focus=pending&sort=date&per_page=5&page=2')
            ->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(
            array_map(fn ($i) => 'COT-NEWEST-'.$i, range(0, 8)),
            array_column(array_merge($first->json('data'), $second->json('data')), 'folio')
        );
    }

    #[Test]
    public function oldest_sort_orders_pending_and_ready_quotes_before_pagination(): void
    {
        $statuses = ['solicitud_cotizaciones', 'en_elaboracion', 'pendiente_envio'];
        for ($i = 0; $i < 9; $i++) {
            $this->quote('COT-OLDEST-'.$i, $statuses[$i % 3])
                ->forceFill(['created_at' => now()->subDays($i + 10)])->save();
        }
        $this->quote('COT-OLDEST-SENT', 'pendiente_envio')
            ->forceFill(['created_at' => now()->subDays(30), 'sent_at' => now()])->save();

        $first = $this->getJson('/api/dashboard/quotes?focus=pending&per_page=5&sort=oldest')->assertOk();
        $second = $this->getJson('/api/dashboard/quotes?focus=pending&per_page=5&sort=oldest&page=2')->assertOk();
        $first->assertJsonPath('meta.total', 9)->assertJsonCount(5, 'data');
        $second->assertJsonCount(4, 'data');
        $this->assertSame(
            array_map(fn ($i) => 'COT-OLDEST-'.$i, range(8, 0)),
            array_column(array_merge($first->json('data'), $second->json('data')), 'folio')
        );
        $dates = array_column(array_merge($first->json('data'), $second->json('data')), 'createdAt');
        $sortedDates = $dates;
        sort($sortedDates);
        $this->assertSame($sortedDates, $dates);
        $this->getJson('/api/dashboard/quotes?focus=ready&sort=oldest')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.folio', 'COT-OLDEST-8')
            ->assertJsonPath('data.2.folio', 'COT-OLDEST-2');
        $this->getJson('/api/dashboard/quotes?focus=pending&sort=date')->assertOk()
            ->assertJsonPath('data.0.folio', 'COT-OLDEST-0');
    }

    #[Test]
    public function idle_alerts_include_three_day_boundary_and_sort_actual_inactivity_oldest_first(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-29 12:00:00'));
        \App\Models\AppSetting::current()->update(['unanswered_quote_days' => 3]);
        $sales = $this->demoUser('ventas');
        $this->quote('COT-IDLE-20', 'en_elaboracion', $sales)->forceFill([
            'last_activity_at' => now()->subDays(20), 'updated_at' => now(),
        ])->save();
        $this->quote('COT-IDLE-LEGACY-15', 'en_elaboracion', $sales)->forceFill([
            'last_activity_at' => null, 'updated_at' => now()->subDays(15),
        ])->save(['timestamps' => false]);
        for ($days = 3; $days <= 13; $days++) {
            $this->quote('COT-IDLE-'.$days, 'en_elaboracion', $sales)->forceFill([
                'last_activity_at' => now()->subDays($days), 'updated_at' => now(),
            ])->save();
        }
        $this->quote('COT-IDLE-2', 'en_elaboracion', $sales)->forceFill([
            'last_activity_at' => now()->subDays(2),
        ])->save();
        $alerts = $this->getJson('/api/dashboard')->assertOk()->json('alerts.unansweredQuotes');
        $this->assertCount(13, $alerts);
        $this->assertSame([20, 15, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3], array_column($alerts, 'daysWaiting'));
        $this->assertSame('COT-IDLE-20', $alerts[0]['folio']);
        $this->assertSame('COT-IDLE-3', $alerts[12]['folio']);
        $this->travelBack();
    }

    #[Test]
    public function dashboard_alerts_report_persisted_quote_status_independently_of_priority(): void
    {
        $sales = $this->demoUser('ventas');
        $this->quote('COT-STATUS-REQUEST', 'solicitud_cotizaciones', $sales);
        $this->quote('COT-STATUS-PROGRESS', 'en_elaboracion', $sales)->forceFill([
            'last_activity_at' => now()->subDays(8),
        ])->save();
        $this->quote('COT-STATUS-READY', 'pendiente_envio', $sales)->forceFill([
            'last_activity_at' => now()->subDays(8),
        ])->save();
        $alerts = $this->getJson('/api/dashboard')->assertOk()->json('alerts');
        $purchase = collect($alerts['purchaseRequestQuotes'])->firstWhere('folio', 'COT-STATUS-REQUEST');
        $progress = collect($alerts['unansweredQuotes'])->firstWhere('folio', 'COT-STATUS-PROGRESS');
        $ready = collect($alerts['unansweredQuotes'])->firstWhere('folio', 'COT-STATUS-READY');
        $this->assertSame('solicitud_cotizaciones', $purchase['status']);
        $this->assertSame('en_elaboracion', $progress['status']);
        $this->assertSame('pendiente_envio', $ready['status']);
    }

}
