from pathlib import Path

cc = Path(r"c:\laragon\www\cotizacion\app\Http\Controllers\Api\ClienteController.php")
text = cc.read_text(encoding="utf-8")
text = text.replace("} catch (QueryException) {", "} catch (QueryException $e) {")
cc.write_text(text, encoding="utf-8")
print("ClienteController fixed")

das = Path(r"c:\laragon\www\cotizacion\app\Services\Analytics\DashboardAnalyticsService.php")
dt = das.read_text(encoding="utf-8")
dt = dt.replace(
    "->whereIn('status', self::PENDING_REQUEST_STATUSES)\n            ->count();",
    "->whereIn('status', self::PENDING_REQUEST_STATUSES, 'and', false)\n            ->count();",
)
das.write_text(dt, encoding="utf-8")
print("DashboardAnalyticsService fixed")
