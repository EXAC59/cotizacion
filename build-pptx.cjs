const PptxGenJS = require("pptxgenjs");

const pres = new PptxGenJS();
pres.defineLayout({ name: "W", width: 13.333, height: 7.5 });
pres.layout = "W";

// ---- Paleta de colores ----
const C = {
  navy: "1F2A44",
  blue: "2563EB",
  lightBlue: "DBEAFE",
  green: "16A34A",
  lightGreen: "DCFCE7",
  red: "DC2626",
  lightRed: "FEE2E2",
  orange: "EA580C",
  gray: "64748B",
  lightGray: "F1F5F9",
  white: "FFFFFF",
  dark: "0F172A",
  codeBg: "1E293B",
  codeText: "E2E8F0",
};

const F = {
  title: "Montserrat",
  body: "Open Sans",
  code: "Courier New",
};

function bg(slide, color) {
  slide.background = { color };
}

function header(slide, num, title) {
  slide.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 1.1, fill: { color: C.navy } });
  slide.addShape(pres.ShapeType.rect, { x: 0, y: 1.1, w: 13.333, h: 0.08, fill: { color: C.blue } });
  slide.addText("COTIZACIÓN · Feature: Revisión de Solicitudes", {
    x: 0.5, y: 0.12, w: 10, h: 0.4, fontFace: F.body, fontSize: 12, color: C.lightBlue, bold: true,
  });
  slide.addText(title, {
    x: 0.5, y: 0.45, w: 11.5, h: 0.55, fontFace: F.title, fontSize: 24, color: C.white, bold: true,
  });
  slide.addText(String(num), {
    x: 12.3, y: 0.12, w: 0.8, h: 0.4, fontFace: F.title, fontSize: 14, color: C.lightBlue, align: "right", bold: true,
  });
}

function card(slide, x, y, w, h, fill, line) {
  slide.addShape(pres.ShapeType.roundRect, {
    x, y, w, h, rectRadius: 0.1, fill: { color: fill }, line: line ? { color: line, width: 1 } : { type: "none" },
  });
}

function codeBox(slide, x, y, w, h, codeLines, bad) {
  card(slide, x, y, w, h, C.codeBg);
  const text = codeLines.map((l) => ({ text: l, options: { fontFace: F.code, fontSize: 12, color: C.codeText, breakLine: true } }));
  slide.addText(text, { x: x + 0.2, y: y + 0.15, w: w - 0.4, h: h - 0.3, valign: "top", lineSpacingMultiple: 1.1 });
}

// ======================================================
// SLIDE 1 — TÍTULO
// ======================================================
{
  const s = pres.addSlide();
  bg(s, C.navy);
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 0.25, fill: { color: C.blue } });
  s.addText("REPORTE DE TRABAJO", {
    x: 0, y: 1.6, w: 13.333, h: 0.5, align: "center", fontFace: F.body, fontSize: 16, color: C.lightBlue, charSpacing: 3, bold: true,
  });
  s.addText("Revisión de Solicitudes", {
    x: 0, y: 2.2, w: 13.333, h: 1.2, align: "center", fontFace: F.title, fontSize: 48, color: C.white, bold: true,
  });
  s.addText("Cómo se corrigió un bug en la base de datos y se dejó todo funcionando", {
    x: 0, y: 3.5, w: 13.333, h: 0.6, align: "center", fontFace: F.body, fontSize: 18, color: C.lightBlue,
  });
  // Cajas info
  const items = [
    ["PROYECTO", "Cotización (Laravel + Vue)"],
    ["FECHA", "12 de agosto de 2026"],
    ["ESTADO", "✅ Completado"],
  ];
  items.forEach((it, i) => {
    const x = 1.4 + i * 3.85;
    card(s, x, 4.6, 3.4, 1.5, C.lightGray, C.blue);
    s.addText(it[0], { x, y: 4.75, w: 3.4, h: 0.4, align: "center", fontFace: F.body, fontSize: 12, color: C.gray, bold: true, charSpacing: 2 });
    s.addText(it[1], { x: x + 0.1, y: 5.15, w: 3.2, h: 0.8, align: "center", fontFace: F.title, fontSize: 15, color: C.navy, bold: true });
  });
}

// ======================================================
// SLIDE 2 — ¿QUÉ SE HIZO?
// ======================================================
{
  const s = pres.addSlide();
  header(s, 2, "¿Qué se hizo?");
  bg(s, C.white);

  s.addText("Se implementó una función para saber quién revisó cada solicitud de cotización.", {
    x: 0.6, y: 1.5, w: 12, h: 0.5, fontFace: F.body, fontSize: 16, color: C.dark, bold: true,
  });

  const steps = [
    ["1", "Base de datos", "Se añadieron 2 columnas nuevas a la tabla de solicitudes: quién la revisó y cuándo."],
    ["2", "Backend (Laravel)", "Al abrir el detalle de una solicitud, el sistema guarda quién la vio. El primero en verla 'gana'."],
    ["3", "Dashboard", "El inicio muestra una alerta con las solicitudes que aún no han sido revisadas."],
    ["4", "Frontend (Vue)", "Se conectó la interfaz para mostrar el nombre del revisor y la fecha."],
  ];

  steps.forEach((st, i) => {
    const y = 2.2 + i * 1.15;
    card(s, 0.6, y, 12.1, 1.0, C.lightGray);
    s.addShape(pres.ShapeType.ellipse, { x: 0.85, y: y + 0.2, w: 0.6, h: 0.6, fill: { color: C.blue } });
    s.addText(st[0], { x: 0.85, y: y + 0.2, w: 0.6, h: 0.6, align: "center", valign: "middle", fontFace: F.title, fontSize: 20, color: C.white, bold: true });
    s.addText(st[1], { x: 1.7, y: y + 0.12, w: 3.2, h: 0.75, valign: "middle", fontFace: F.title, fontSize: 15, color: C.navy, bold: true });
    s.addText(st[2], { x: 5.0, y: y + 0.12, w: 7.5, h: 0.75, valign: "middle", fontFace: F.body, fontSize: 13, color: C.dark });
  });
}

// ======================================================
// SLIDE 3 — EL PROBLEMA (SIMPLE)
// ======================================================
{
  const s = pres.addSlide();
  header(s, 3, "El problema que apareció");
  bg(s, C.white);

  s.addText("Una prueba automática (test) fallaba y no dejaba dormir a la feature:", {
    x: 0.6, y: 1.4, w: 12, h: 0.5, fontFace: F.body, fontSize: 16, color: C.dark, bold: true,
  });

  card(s, 0.6, 2.1, 12.1, 1.1, C.lightRed, C.red);
  s.addText([
    { text: "❌  Test fallido:  ", options: { bold: true, color: C.red, fontFace: F.body, fontSize: 15 } },
    { text: "el_primer_revisor_gana", options: { bold: true, color: C.dark, fontFace: F.code, fontSize: 15 } },
    { text: "  —  esperaba el número 1, recibió el texto '1'", options: { color: C.dark, fontFace: F.body, fontSize: 15 } },
  ], { x: 0.9, y: 2.1, w: 11.5, h: 1.1, valign: "middle" });

  s.addText("¿Por qué importa? Porque esa prueba protege una regla de negocio importante: cuando dos personas abren la misma solicitud, el primero en revisarla debe quedar registrado como el revisor.", {
    x: 0.6, y: 3.5, w: 12, h: 1.0, fontFace: F.body, fontSize: 14, color: C.gray,
  });

  // Dos columnas: síntoma vs consecuencia
  card(s, 0.6, 4.7, 5.9, 2.0, C.lightGray);
  s.addText("SÍNTOMA", { x: 0.8, y: 4.85, w: 5.5, h: 0.4, fontFace: F.body, fontSize: 12, color: C.orange, bold: true, charSpacing: 2 });
  s.addText("Un '1' como texto no es lo mismo que un 1 como número. La comparación falla.", { x: 0.8, y: 5.25, w: 5.5, h: 1.3, fontFace: F.body, fontSize: 13, color: C.dark });

  card(s, 6.8, 4.7, 5.9, 2.0, C.lightGray);
  s.addText("CONSECUENCIA", { x: 7.0, y: 4.85, w: 5.5, h: 0.4, fontFace: F.body, fontSize: 12, color: C.red, bold: true, charSpacing: 2 });
  s.addText("El sistema no sabía con seguridad quién revisó la solicitud. La función no servía.", { x: 7.0, y: 5.25, w: 5.5, h: 1.3, fontFace: F.body, fontSize: 13, color: C.dark });
}

// ======================================================
// SLIDE 4 — LA CAUSA (VISUAL)
// ======================================================
{
  const s = pres.addSlide();
  header(s, 4, "¿Cuál fue la causa?");
  bg(s, C.white);

  s.addText("La columna nueva se creó con el tipo de dato equivocado.", {
    x: 0.6, y: 1.35, w: 12, h: 0.5, fontFace: F.body, fontSize: 16, color: C.dark, bold: true,
  });

  // Diagrama comparativo
  s.addText("CÓMO ESTABA LA TABLA USUARIOS", { x: 0.6, y: 2.0, w: 6, h: 0.4, fontFace: F.body, fontSize: 12, color: C.gray, bold: true, charSpacing: 1 });
  s.addText("CÓMO SE CREÓ LA COLUMNA NUEVA", { x: 7.0, y: 2.0, w: 6, h: 0.4, fontFace: F.body, fontSize: 12, color: C.gray, bold: true, charSpacing: 1 });

  card(s, 0.6, 2.5, 5.9, 1.4, C.lightGreen, C.green);
  s.addText([
    { text: "users.id  →  ", options: { fontFace: F.code, fontSize: 13, color: C.dark } },
    { text: "BIGINT (número)", options: { fontFace: F.code, fontSize: 13, color: C.green, bold: true } },
  ], { x: 0.8, y: 2.5, w: 5.5, h: 1.0, valign: "middle" });
  s.addText("created_by → BIGINT (correcto)", { x: 0.8, y: 3.3, w: 5.5, h: 0.5, fontFace: F.code, fontSize: 12, color: C.green, bold: true });

  card(s, 7.0, 2.5, 5.9, 1.4, C.lightRed, C.red);
  s.addText([
    { text: "reviewed_by  →  ", options: { fontFace: F.code, fontSize: 13, color: C.dark } },
    { text: "UUID (texto)", options: { fontFace: F.code, fontSize: 13, color: C.red, bold: true } },
  ], { x: 7.2, y: 2.5, w: 5.5, h: 1.0, valign: "middle" });
  s.addText("¡No coincide con users.id!", { x: 7.2, y: 3.3, w: 5.5, h: 0.5, fontFace: F.code, fontSize: 12, color: C.red, bold: true });

  // Flecha / unión
  s.addShape(pres.ShapeType.line, { x: 6.5, y: 3.2, w: 0.3, h: 0, line: { color: C.orange, width: 3, endArrowType: "triangle" } });

  card(s, 0.6, 4.3, 12.1, 2.4, C.lightGray);
  s.addText("EL EFECTO EN CADENA", { x: 0.8, y: 4.45, w: 11, h: 0.4, fontFace: F.body, fontSize: 12, color: C.orange, bold: true, charSpacing: 2 });
  const chain = [
    "Tipo UUID (texto) en lugar de número  →  la llave foránea (relación) no se crea bien en la base real",
    "En los tests (SQLite) pasaba, pero en PostgreSQL/MySQL la relación falla",
    "Al leer el dato guardado, llega como texto '1' en vez de número 1",
    "La comparación de la prueba falla  →  el test se rompe",
  ];
  s.addText(chain.map((c, i) => ({ text: `${i + 1}.  ${c}`, options: { fontFace: F.body, fontSize: 14, color: C.dark, breakLine: true, paraSpaceAfter: 6 } })), {
    x: 0.8, y: 4.85, w: 11.7, h: 1.8, valign: "top",
  });
}

// ======================================================
// SLIDE 5 — LA SOLUCIÓN (CÓDIGO)
// ======================================================
{
  const s = pres.addSlide();
  header(s, 5, "La solución aplicada");
  bg(s, C.white);

  s.addText("Se cambió el tipo de dato por el correcto y se usó la forma estándar de Laravel.", {
    x: 0.6, y: 1.35, w: 12, h: 0.5, fontFace: F.body, fontSize: 15, color: C.dark, bold: true,
  });

  // Antes
  s.addText("❌ ANTES (incorrecto)", { x: 0.6, y: 2.0, w: 6, h: 0.4, fontFace: F.body, fontSize: 13, color: C.red, bold: true });
  codeBox(s, 0.6, 2.4, 5.9, 2.3, [
    "$table->uuid('reviewed_by')",
    "    ->nullable()",
    "    ->after('status');",
    "$table->foreign('reviewed_by')",
    "    ->references('id')",
    "    ->on('users')",
    "    ->nullOnDelete();",
  ], true);

  // Después
  s.addText("✅ DESPUÉS (correcto)", { x: 7.0, y: 2.0, w: 6, h: 0.4, fontFace: F.body, fontSize: 13, color: C.green, bold: true });
  codeBox(s, 7.0, 2.4, 5.9, 2.3, [
    "$table->foreignId('reviewed_by')",
    "    ->nullable()",
    "    ->after('status')",
    "    ->constrained('users')",
    "    ->nullOnDelete();",
  ], false);

  // Explicación
  card(s, 0.6, 5.0, 12.1, 1.8, C.lightBlue);
  s.addText("¿Qué cambió realmente?", { x: 0.8, y: 5.1, w: 11, h: 0.4, fontFace: F.title, fontSize: 14, color: C.navy, bold: true });
  s.addText([
    { text: "•  uuid()  →  foreignId()   ", options: { fontFace: F.code, fontSize: 13, color: C.navy, bold: true } },
    { text: "(ahora es número, igual que created_by)", options: { fontFace: F.body, fontSize: 13, color: C.dark, breakLine: true } },
    { text: "•  foreign() manual  →  constrained('users')   ", options: { fontFace: F.code, fontSize: 13, color: C.navy, bold: true } },
    { text: "(forma recomendada de Laravel, crea la relación sola)", options: { fontFace: F.body, fontSize: 13, color: C.dark } },
  ], { x: 0.8, y: 5.5, w: 11.7, h: 1.2, valign: "top" });
}

// ======================================================
// SLIDE 6 — RESULTADO: TESTS
// ======================================================
{
  const s = pres.addSlide();
  header(s, 6, "Resultado: las pruebas pasan");
  bg(s, C.white);

  s.addText("Las 6 pruebas de la función de revisión ahora funcionan correctamente:", {
    x: 0.6, y: 1.35, w: 12, h: 0.5, fontFace: F.body, fontSize: 16, color: C.dark, bold: true,
  });

  const rows = [
    ["se_crea_sin_revisor", "✅", "✅"],
    ["marca_como_revisada_al_abrir_el_detalle", "✅", "✅"],
    ["el_primer_revisor_gana", "❌", "✅"],
    ["el_dashboard_lista_solo_las_sin_revisar", "✅", "✅"],
    ["el_dashboard_lista_solo_las_en_elaboracion", "✅", "✅"],
    ["no_permite_editar_lineas_una_vez_enviada", "✅", "✅"],
    ["permite_editar_lineas_en_elaboracion", "✅", "✅"],
  ];

  // Tabla
  const tblX = 0.6;
  const tblY = 2.1;
  const wCol = [8.1, 1.8, 1.8];
  // header
  s.addShape(pres.ShapeType.rect, { x: tblX, y: tblY, w: wCol[0], h: 0.55, fill: { color: C.navy } });
  s.addShape(pres.ShapeType.rect, { x: tblX + wCol[0], y: tblY, w: wCol[1], h: 0.55, fill: { color: C.navy } });
  s.addShape(pres.ShapeType.rect, { x: tblX + wCol[0] + wCol[1], y: tblY, w: wCol[2], h: 0.55, fill: { color: C.navy } });
  s.addText("Prueba (test)", { x: tblX, y: tblY, w: wCol[0], h: 0.55, align: "left", valign: "middle", fontFace: F.body, fontSize: 13, color: C.white, bold: true });
  s.addText("ANTES", { x: tblX + wCol[0], y: tblY, w: wCol[1], h: 0.55, align: "center", valign: "middle", fontFace: F.body, fontSize: 13, color: C.white, bold: true });
  s.addText("DESPUÉS", { x: tblX + wCol[0] + wCol[1], y: tblY, w: wCol[2], h: 0.55, align: "center", valign: "middle", fontFace: F.body, fontSize: 13, color: C.white, bold: true });

  rows.forEach((r, i) => {
    const y = tblY + 0.55 + i * 0.55;
    const fill = i % 2 === 0 ? C.white : C.lightGray;
    s.addShape(pres.ShapeType.rect, { x: tblX, y, w: wCol[0], h: 0.55, fill: { color: fill }, line: { color: "E2E8F0", width: 0.5 } });
    s.addShape(pres.ShapeType.rect, { x: tblX + wCol[0], y, w: wCol[1], h: 0.55, fill: { color: fill }, line: { color: "E2E8F0", width: 0.5 } });
    s.addShape(pres.ShapeType.rect, { x: tblX + wCol[0] + wCol[1], y, w: wCol[2], h: 0.55, fill: { color: fill }, line: { color: "E2E8F0", width: 0.5 } });
    s.addText(r[0], { x: tblX + 0.15, y, w: wCol[0] - 0.2, h: 0.55, valign: "middle", fontFace: F.code, fontSize: 11, color: C.dark });
    s.addText(r[1], { x: tblX + wCol[0], y, w: wCol[1], h: 0.55, align: "center", valign: "middle", fontFace: F.body, fontSize: 16, color: r[1] === "✅" ? C.green : C.red });
    s.addText(r[2], { x: tblX + wCol[0] + wCol[1], y, w: wCol[2], h: 0.55, align: "center", valign: "middle", fontFace: F.body, fontSize: 16, color: r[2] === "✅" ? C.green : C.red });
  });

  card(s, 0.6, 6.55, 12.1, 0.55, C.lightGreen, C.green);
  s.addText("✔  La prueba que fallaba (el_primer_revisor_gana) ahora pasa. Cero regresiones.", {
    x: 0.6, y: 6.55, w: 12.1, h: 0.55, align: "center", valign: "middle", fontFace: F.body, fontSize: 13, color: C.green, bold: true,
  });
}

// ======================================================
// SLIDE 7 — SUITE COMPLETA Y NOTA
// ======================================================
{
  const s = pres.addSlide();
  header(s, 7, "Estado general del proyecto");
  bg(s, C.white);

  // Grande: 247 / 250
  card(s, 0.6, 1.5, 5.9, 2.6, C.lightGreen, C.green);
  s.addText("247", { x: 0.6, y: 1.7, w: 5.9, h: 1.3, align: "center", fontFace: F.title, fontSize: 60, color: C.green, bold: true });
  s.addText("pruebas pasan", { x: 0.6, y: 3.0, w: 5.9, h: 0.7, align: "center", fontFace: F.body, fontSize: 16, color: C.dark, bold: true });

  card(s, 6.8, 1.5, 5.9, 2.6, C.lightRed, C.red);
  s.addText("3", { x: 6.8, y: 1.7, w: 5.9, h: 1.3, align: "center", fontFace: F.title, fontSize: 60, color: C.red, bold: true });
  s.addText("pruebas fallan (preexistentes)", { x: 6.8, y: 3.0, w: 5.9, h: 0.7, align: "center", fontFace: F.body, fontSize: 14, color: C.dark, bold: true });

  s.addText("Total: 250 pruebas en el sistema", { x: 0.6, y: 4.25, w: 12, h: 0.4, align: "center", fontFace: F.body, fontSize: 14, color: C.gray, bold: true });

  card(s, 0.6, 4.8, 12.1, 2.0, C.lightGray);
  s.addText("⚠  NOTA IMPORTANTE", { x: 0.8, y: 4.9, w: 11, h: 0.4, fontFace: F.body, fontSize: 13, color: C.orange, bold: true, charSpacing: 1 });
  s.addText([
    { text: "Las 3 pruebas que aún fallan ", options: { fontFace: F.body, fontSize: 13, color: C.dark } },
    { text: "no tienen nada que ver con este arreglo", options: { fontFace: F.body, fontSize: 13, color: C.red, bold: true } },
    { text: ". Ya fallaban antes (están en el historial de pruebas). Pertenecen al módulo del comparador de precios:", options: { fontFace: F.body, fontSize: 13, color: C.dark, breakLine: true, paraSpaceBefore: 4 } },
    { text: "•  CvaConnectorTest — nombre de almacén (mayúsculas)", options: { fontFace: F.code, fontSize: 12, color: C.dark, breakLine: true } },
    { text: "•  ApiAuthorizationTest — permiso de ventas (403 vs 202)", options: { fontFace: F.code, fontSize: 12, color: C.dark, breakLine: true } },
    { text: "•  ComparatorSettingsTest — preferencias de usuario (403 vs 200)", options: { fontFace: F.code, fontSize: 12, color: C.dark } },
  ], { x: 0.8, y: 5.3, w: 11.7, h: 1.4, valign: "top" });
}

// ======================================================
// SLIDE 8 — LECCIONES
// ======================================================
{
  const s = pres.addSlide();
  header(s, 8, "¿Qué aprendimos?");
  bg(s, C.white);

  s.addText("Buenas prácticas para que este error no se repita:", {
    x: 0.6, y: 1.4, w: 12, h: 0.5, fontFace: F.body, fontSize: 16, color: C.dark, bold: true,
  });

  const lessons = [
    ["🔗", "Tipos de datos coherentes", "Si la tabla usa números (BIGINT) para su ID, las columnas que apunten a ella también deben ser números."],
    ["📐", "Usar lo que recomienda el framework", "constrained('users') es más seguro que escribir la relación a mano."],
    ["🗄️", "Probar en la base real", "SQLite (pruebas) acepta cosas que PostgreSQL/MySQL rechazan. Hay que verificar en ambos."],
    ["🛡️", "Las pruebas protegen reglas de negocio", "El test 'primer revisor gana' evita que dos personas se lleven el crédito por error."],
  ];

  lessons.forEach((l, i) => {
    const x = 0.6 + (i % 2) * 6.2;
    const y = 2.1 + Math.floor(i / 2) * 2.3;
    card(s, x, y, 5.9, 2.1, C.lightGray);
    s.addShape(pres.ShapeType.ellipse, { x: x + 0.25, y: y + 0.25, w: 0.8, h: 0.8, fill: { color: C.blue } });
    s.addText(l[0], { x: x + 0.25, y: y + 0.25, w: 0.8, h: 0.8, align: "center", valign: "middle", fontSize: 24 });
    s.addText(l[1], { x: x + 1.2, y: y + 0.2, w: 4.5, h: 0.6, fontFace: F.title, fontSize: 14, color: C.navy, bold: true, valign: "middle" });
    s.addText(l[2], { x: x + 1.2, y: y + 0.75, w: 4.5, h: 1.2, fontFace: F.body, fontSize: 12, color: C.dark });
  });
}

// ======================================================
// SLIDE 9 — RESUMEN
// ======================================================
{
  const s = pres.addSlide();
  bg(s, C.navy);
  s.addShape(pres.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 0.25, fill: { color: C.green } });
  s.addText("RESUMEN", { x: 0, y: 0.8, w: 13.333, h: 0.7, align: "center", fontFace: F.title, fontSize: 32, color: C.white, bold: true });

  const summary = [
    ["✅", "Tarea completada", "La función de revisión de solicitudes quedó funcionando."],
    ["🔧", "1 bug corregido", "Columna reviewed_by con tipo de dato equivocado (texto en vez de número)."],
    ["🧪", "6 pruebas en verde", "La que fallaba ahora pasa; ninguna se rompió."],
    ["⏱️", "Menos de 10 minutos", "El arreglo fue pequeño pero crítico."],
  ];

  summary.forEach((it, i) => {
    const y = 1.9 + i * 1.15;
    card(s, 1.2, y, 10.9, 1.0, C.lightGray);
    s.addText(it[0], { x: 1.4, y, w: 1.0, h: 1.0, align: "center", valign: "middle", fontSize: 30 });
    s.addText(it[1], { x: 2.6, y: y + 0.1, w: 9.4, h: 0.45, fontFace: F.title, fontSize: 16, color: C.navy, bold: true });
    s.addText(it[2], { x: 2.6, y: y + 0.5, w: 9.4, h: 0.45, fontFace: F.body, fontSize: 13, color: C.dark });
  });

  s.addText("Proyecto Cotización · 12 de agosto de 2026", { x: 0, y: 6.9, w: 13.333, h: 0.4, align: "center", fontFace: F.body, fontSize: 12, color: C.lightBlue });
}

pres.writeFile({ fileName: "presentacion-revision-solicitudes.pptx" }).then((fn) => {
  console.log("Creado:", fn);
});
