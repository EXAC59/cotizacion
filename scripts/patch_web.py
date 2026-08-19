from pathlib import Path
web = Path(r"c:\laragon\www\cotizacion\routes\web.php")
text = web.read_text(encoding="utf-8")
if "'dashboard'" not in text:
    text = text.replace(
        "foreach (['login', 'solicitudes'",
        "foreach (['login', 'dashboard', 'solicitudes'",
    )
    web.write_text(text, encoding="utf-8")
    print("web.php updated")
else:
    print("web.php already has dashboard")
