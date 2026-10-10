"""Arma el Excel de los anuncios de Facebook e Instagram (AEP 2026) a partir de ads/anuncios.json.

    python3 ads/libro.py [salida.xlsx] [--planes 2027_Plan_Comparison_All_Carriers.xlsx]

Con --planes comprueba que cada cifra de la hoja «Planes (con aprobación)» salga del archivo de planes de Isabel.
Los textos de cada anuncio quedan en filas sueltas (una línea por fila) para poder copiarlos sin comillas.
"""
import json
import math
import os
import sys
from datetime import date

from openpyxl import Workbook
from openpyxl.formatting.rule import CellIsRule, FormulaRule
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation
from openpyxl.worksheet.hyperlink import Hyperlink

AQUI = os.path.dirname(os.path.abspath(__file__))
args = [a for a in sys.argv[1:]]
PLANES = None
if "--planes" in args:
    i = args.index("--planes")
    PLANES = args[i + 1]
    del args[i:i + 2]
OUT = args[0] if args else os.path.join(AQUI, "salida", "Anuncios-Facebook-AEP-2026.xlsx")
DATOS = json.load(open(os.path.join(AQUI, "anuncios.json"), encoding="utf-8"))

# ── marca ────────────────────────────────────────────────────────────────
NAVY, AZUL, CIELO, FONDO, GRIS, DURAZNO = "333A4D", "3D8FD6", "A9D4F0", "EAF4FB", "5C6270", "F2A977"
AMARILLO = "FFF2CC"          # casillas que llena Isabel
ROJO, ROJO_FONDO = "B42318", "FDECEA"
VERDE, VERDE_FONDO = "1E7B4F", "EAF5EE"
FUENTE = "Arial"

fino = Side(style="thin", color="D4D9E1")
BORDE = Border(left=fino, right=fino, top=fino, bottom=fino)
ARRIBA = Alignment(wrap_text=True, vertical="top")
CENTRO = Alignment(horizontal="center", vertical="center", wrap_text=True)
FECHA = '[$-080A]ddd d mmm yyyy'
DINERO = '"$"#,##0.00'
DINERO0 = '"$"#,##0'


def fnt(size=10, bold=False, color="222222", italic=False, underline=None):
    return Font(name=FUENTE, size=size, bold=bold, color=color, italic=italic, underline=underline)


def rel(hex_):
    return PatternFill("solid", fgColor=hex_)


def cf(hex_):
    """Relleno para formato condicional: Excel toma el color de bgColor, así que se ponen los dos."""
    return PatternFill(start_color=hex_, end_color=hex_, fill_type="solid")


MESES = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"]
DIAS = ["lun", "mar", "mié", "jue", "vie", "sáb", "dom"]


def ymd(s):
    y, m, d = map(int, s.split("-"))
    return date(y, m, d)


def corto(s):
    d = ymd(s)
    return f"{DIAS[d.weekday()]} {d.day} {MESES[d.month - 1]}"


def lineas_est(texto, ancho):
    texto = "" if texto is None else str(texto)
    cpl = max(1, int(ancho * 1.12))
    return sum(max(1, math.ceil(len(p) / cpl)) for p in texto.split("\n"))


def altura(ws, fila, anchos, minimo=16, linea=13.4, maximo=409, extra=None):
    """Altura de la fila según el texto más largo (Excel no la ajusta solo en celdas con fórmula)."""
    n = 1
    for col, ancho in anchos.items():
        v = extra.get((fila, col)) if extra and (fila, col) in extra else ws.cell(row=fila, column=col).value
        if isinstance(v, str) and v.startswith("="):
            continue
        n = max(n, lineas_est(v, ancho))
    ws.row_dimensions[fila].height = max(minimo, min(maximo, n * linea + 5))


def anchos(ws, lista):
    for i, w in enumerate(lista, 1):
        ws.column_dimensions[get_column_letter(i)].width = w
    return {i: w for i, w in enumerate(lista, 1)}


def titulo(ws, texto, sub=None, cols=4):
    ws["A1"] = texto
    ws["A1"].font = fnt(17, True, NAVY)
    ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=cols)
    ws.row_dimensions[1].height = 28
    if sub:
        ws["A2"] = sub
        ws["A2"].font = fnt(10, False, GRIS, italic=True)
        ws["A2"].alignment = Alignment(wrap_text=True, vertical="top")
        ws.merge_cells(start_row=2, start_column=1, end_row=2, end_column=cols)


def banda(ws, fila, texto, cols, color=NAVY, fg="FFFFFF", size=11, alto=24, desde=1):
    ws.cell(row=fila, column=desde, value=texto)
    for c in range(desde, cols + 1):
        ws.cell(row=fila, column=c).fill = rel(color)
    ws.cell(row=fila, column=desde).font = fnt(size, True, fg)
    ws.cell(row=fila, column=desde).alignment = Alignment(vertical="center", wrap_text=True)
    ws.merge_cells(start_row=fila, start_column=desde, end_row=fila, end_column=cols)
    ws.row_dimensions[fila].height = alto


def encabezado(ws, fila, etiquetas, color=NAVY):
    for c, et in enumerate(etiquetas, 1):
        cel = ws.cell(row=fila, column=c, value=et)
        cel.font = fnt(10, True, "FFFFFF")
        cel.fill = rel(color)
        cel.alignment = CENTRO
        cel.border = BORDE
    ws.row_dimensions[fila].height = 30


def celda(ws, fila, col, valor, negrita=False, color="222222", fondo=None, formato=None, borde=True, alinear=None, size=10, italica=False):
    cel = ws.cell(row=fila, column=col, value=valor)
    cel.font = fnt(size, negrita, color, italic=italica)
    cel.alignment = alinear or ARRIBA
    if fondo:
        cel.fill = rel(fondo)
    if formato:
        cel.number_format = formato
    if borde:
        cel.border = BORDE
    return cel


def entrada(ws, fila, col, valor=None, formato=None):
    cel = celda(ws, fila, col, valor, fondo=AMARILLO, formato=formato)
    return cel


def vinculo(cel, destino, texto=None):
    if texto is not None:
        cel.value = texto
    cel.hyperlink = Hyperlink(ref=cel.coordinate, location=destino, display=str(cel.value))
    cel.font = fnt(10, False, AZUL, underline="single")


def imprimir(ws, horizontal=True):
    ws.page_setup.orientation = "landscape" if horizontal else "portrait"
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 0
    ws.sheet_properties.pageSetUpPr.fitToPage = True


wb = Workbook()
HOJA_EMPIEZA = "Empieza aquí"
HOJA_CAMP = "Campañas"
HOJA_ANUN = "Anuncios"
HOJA_FORM = "Formulario"
HOJA_SEM = "Semana a semana"
HOJA_CUMP = "Cumplimiento"
HOJA_PLAN = "Planes (con aprobación)"
HOJA_SEG = "Seguimiento"
HOJA_SUBIR = "Cómo subirlo"
q = lambda h: "'" + h + "'"        # nombre de hoja citado para fórmulas y vínculos

# Celdas de entrada de «Empieza aquí» (las lee todo lo demás)
C_ORG, C_PLANES, C_PRIV, C_CPL = "C6", "C7", "C8", "C9"
REF_ORG = f"{q(HOJA_EMPIEZA)}!${C_ORG[0]}${C_ORG[1:]}"
REF_PLANES = f"{q(HOJA_EMPIEZA)}!${C_PLANES[0]}${C_PLANES[1:]}"
REF_PRIV = f"{q(HOJA_EMPIEZA)}!${C_PRIV[0]}${C_PRIV[1:]}"
REF_CPL = f"{q(HOJA_EMPIEZA)}!${C_CPL[0]}${C_CPL[1:]}"

LEGAL = DATOS["legal"]
TPMO_PARTES = LEGAL["tpmo"].split("{ORG}")
TPMO_A = TPMO_PARTES[0]
TPMO_B, TPMO_C = TPMO_PARTES[1].split("{PLANES}")
TPMO_FORMULA = (f'=CONCATENATE("{TPMO_A}",IF({REF_ORG}="","[número de organizaciones]",{REF_ORG}),"{TPMO_B}",'
                f'IF({REF_PLANES}="","[número de planes]",{REF_PLANES}),"{TPMO_C}")')
TPMO_MUESTRA = LEGAL["tpmo"].replace("{ORG}", "[número de organizaciones]").replace("{PLANES}", "[número de planes]")
assert max(len(TPMO_A), len(TPMO_B), len(TPMO_C)) < 250, "Excel no admite textos de más de 255 caracteres dentro de una fórmula"

ANUNCIOS = DATOS["anuncios"]
H_IMG = lambda i, a, fmt: f"{i + 1:02d}-{a['id']}_{fmt}-1080x{1350 if fmt == 'feed' else 1920}.png"

# ═════════════════════════════════════════════════════════════════════════
# 1 · Empieza aquí
# ═════════════════════════════════════════════════════════════════════════
ws = wb.active
ws.title = HOJA_EMPIEZA
ws.sheet_properties.tabColor = DURAZNO
W = anchos(ws, [3, 46, 44, 62])
ws["B1"] = "Anuncios de Facebook e Instagram · AEP 2026"
ws["B1"].font = fnt(18, True, NAVY)
ws.merge_cells("B1:D1")
ws.row_dimensions[1].height = 30
ws["B2"] = ("Medicare with Isabel · Paquete listo para subir. Yo escribo y preparo los anuncios; tú (o Sammy, o tu diseñadora) "
            "los subes y los enciendes en Meta. Los números de lo que gastas y consigues los anotas en «Seguimiento».")
ws["B2"].font = fnt(10, False, GRIS, italic=True)
ws["B2"].alignment = ARRIBA
ws.merge_cells("B2:D2")
ws.row_dimensions[2].height = 44

banda(ws, 4, "1 · Llena esto una sola vez (casillas amarillas)", 4, desde=2)
encabezado(ws, 5, ["", "Dato", "Tu respuesta", "Para qué sirve"])
ws.cell(row=5, column=1).fill = PatternFill()
ws.cell(row=5, column=1).border = Border()
filas_in = [
    (6, "Organizaciones que representas (número TPMO)", "Es el mismo número que va en ⚙️ Ajustes del sistema. Se escribe solo en el aviso legal de cada anuncio y del formulario."),
    (7, "Productos que ofreces en tu área (número TPMO)", "Igual: el segundo número del aviso TPMO. Tu FMO te lo confirma."),
    (8, "Enlace de la política de privacidad de tu sitio", "Meta lo exige para usar formularios instantáneos. Debe abrir una página web (no un PDF)."),
    (9, "Costo máximo por lead que aceptas ($)", "Opcional. En «Seguimiento» se pinta en rojo lo que cueste más que esto."),
]
for fila, etiqueta, nota in filas_in:
    celda(ws, fila, 2, etiqueta, negrita=True)
    entrada(ws, fila, 3, None, DINERO if fila == 9 else None)
    celda(ws, fila, 4, nota, color=GRIS)
    altura(ws, fila, W)
dv = DataValidation(type="whole", operator="greaterThanOrEqual", formula1="0", allow_blank=True,
                    errorTitle="Solo un número", error="Escribe solo el número (por ejemplo 12).")
ws.add_data_validation(dv)
dv.add(C_ORG)
dv.add(C_PLANES)
celda(ws, 10, 2, "Estado de tus números", negrita=True)
celda(ws, 10, 3, f'=IF(OR({C_ORG}="",{C_PLANES}=""),"⚠ Falta escribir tus 2 números TPMO antes de publicar","✔ Números TPMO completos")', negrita=True)
ws.merge_cells("C10:D10")
ws.conditional_formatting.add("C10:D10", FormulaRule(formula=[f'OR({C_ORG}="",{C_PLANES}="")'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.conditional_formatting.add("C10:D10", FormulaRule(formula=[f'AND({C_ORG}<>"",{C_PLANES}<>"")'], fill=cf(VERDE_FONDO), font=Font(bold=True, color=VERDE)))
ws.row_dimensions[10].height = 22

banda(ws, 12, "2 · Qué está listo y qué falta", 4, desde=2)
encabezado(ws, 13, ["", "Qué", "Quién", "Nota"])
ws.cell(row=13, column=1).fill = PatternFill()
ws.cell(row=13, column=1).border = Border()
estado = [
    ("✅", "9 anuncios completos: imágenes (feed e historias) y textos A y B", "Listo", "Hoja «Anuncios». Las imágenes van en el archivo .zip."),
    ("✅", "Formulario instantáneo escrito", "Listo", "Hoja «Formulario»: preguntas, permiso para contactarte y pantalla de gracias."),
    ("✅", "Regiones y presupuesto propuestos", "Listo · tú decides cuánto gastar", "Hoja «Campañas». El total se calcula solo."),
    ("✅", "Revisión de cumplimiento (CMS y Meta) de cada texto", "Listo", "Hoja «Cumplimiento». Tu FMO tiene la última palabra."),
    ("⏳", "Tus 2 números TPMO (casillas amarillas de arriba)", "Isabel", "Sin ellos, el aviso legal sale con corchetes."),
    ("⏳", "Enlace de privacidad de tu sitio", "Isabel / Sammy", "Si tu sitio aún no tiene una página de privacidad, hay que crearla antes de subir el formulario."),
    ("⏳", "Que tu FMO revise el material y el texto del formulario (si su proceso lo exige)", "Isabel", "Mándales el Excel y las imágenes tal cual."),
    ("⏳", "Subir a Meta y encender", "Isabel / Sammy / diseñadora", "10 minutos por campaña. Hoja «Cómo subirlo». Yo no puedo entrar a tu cuenta de Meta."),
    ("⛔", "Anuncios con beneficios de un plan específico", "NO publicar", "Solo con aprobación del carrier/FMO. Hoja «Planes (con aprobación)»."),
]
r = 14
for icono, que, quien, nota in estado:
    celda(ws, r, 1, icono, alinear=CENTRO, borde=False, size=12)
    celda(ws, r, 2, que, negrita=True)
    celda(ws, r, 3, quien)
    celda(ws, r, 4, nota, color=GRIS)
    altura(ws, r, W)
    r += 1

r += 1
banda(ws, r, "3 · Fechas que importan", 4, desde=2)
r += 1
fechas = [
    ("Lun 12 oct", "Enviar los 3 anuncios de lanzamiento a revisión de Meta. Tarda hasta ~1 día, y el plan pedía lanzar el 7 de oct: cada día cuenta."),
    ("Mié 14 oct", "Empiezan a correr los anuncios 1, 2 y 3."),
    ("Jue 15 oct", "Abre el AEP: desde hoy se pueden tomar aplicaciones."),
    ("22 oct · 29 oct · 5 nov · 12 nov · 19 nov · 30 nov", "Se suma un anuncio nuevo cada semana (ver «Semana a semana»)."),
    ("Lun 7 dic", "Último día del AEP. Los anuncios se programan para terminar a las 11:59 pm."),
    ("Mar 8 dic", "Comprobar que todos los anuncios están apagados."),
]
for cuando, que in fechas:
    celda(ws, r, 2, cuando, negrita=True)
    celda(ws, r, 3, que)
    ws.merge_cells(start_row=r, start_column=3, end_row=r, end_column=4)
    ws.row_dimensions[r].height = max(18, lineas_est(que, W[3] + W[4]) * 13.4 + 5)
    r += 1

r += 1
banda(ws, r, "4 · Hojas de este archivo", 4, desde=2)
r += 1
hojas = [
    (HOJA_CAMP, "Cómo se configura en Meta, regiones y presupuesto."),
    (HOJA_ANUN, "Los 9 anuncios: imagen, titulares y textos listos para copiar."),
    (HOJA_FORM, "El formulario instantáneo y el primer mensaje al lead."),
    (HOJA_SEM, "Qué enciendes y qué revisas cada semana."),
    (HOJA_CUMP, "Lista de revisión CMS y Meta antes de publicar."),
    (HOJA_PLAN, "Ideas con beneficios de planes concretos. NO publicar sin aprobación."),
    (HOJA_SEG, "Tus números de cada semana, con costo por lead."),
    (HOJA_SUBIR, "Paso a paso en Meta (para Sammy o tu diseñadora)."),
]
for nombre, desc in hojas:
    vinculo(celda(ws, r, 2, nombre), f"{q(nombre)}!A1", nombre)
    celda(ws, r, 3, desc, color=GRIS)
    ws.merge_cells(start_row=r, start_column=3, end_row=r, end_column=4)
    r += 1
imprimir(ws, horizontal=False)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 2 · Campañas
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_CAMP)
ws.sheet_properties.tabColor = AZUL
W = anchos(ws, [34, 34, 14, 18, 22, 44])
titulo(ws, "Campañas · cómo se configura en Meta", "Una campaña con tres conjuntos de anuncios (uno por región). Lo amarillo lo puedes cambiar; los totales se recalculan solos.", 6)

banda(ws, 4, "Campaña", 6)
ajustes = [
    ("Nombre", "AEP 2026 · Clientes potenciales · español"),
    ("Objetivo", "Clientes potenciales (Leads)"),
    ("Categoría de anuncios especiales", "«Productos y servicios financieros» — se marca al crear la campaña. Según las fuentes que consulté, Meta la exige a anunciantes de EE. UU. desde enero de 2025 para anuncios de seguros; si no se declara, pueden rechazar los anuncios o restringir la cuenta. Confírmalo en el Administrador de anuncios."),
    ("Dónde se llenan los datos", "Formulario instantáneo, tipo «Mayor intención» (ver hoja «Formulario»)"),
    ("Presupuesto", "Diario, por conjunto de anuncios (tabla de abajo)"),
    ("Segmentación", "Zona por radio (mínimo 15 millas) + idioma español. Con la categoría especial Meta NO deja elegir edad, género ni código postal, ni usar audiencias parecidas. Por eso los anuncios están en español y hablan de Medicare: el mensaje hace el filtro."),
    ("Ubicaciones", "Automáticas (Advantage+): Facebook e Instagram, Feed, Historias y Reels. Por eso hay imagen 4:5 y 9:16."),
    ("Optimización", "Maximizar el número de clientes potenciales. Sin tope de costo al inicio."),
    ("Identidad", "Tu página de Facebook y tu cuenta de Instagram"),
    ("Sobre «64+» del plan AEP", "La estrategia pedía español · LA/OC/IE · 64+. Con la categoría especial no se puede elegir la edad, así que se cambió a mensaje en español sobre Medicare. Confírmalo al crear la campaña: las reglas de Meta que consulté vienen de resúmenes de terceros, no pude abrir la página oficial."),
]
r = 5
for et, val in ajustes:
    celda(ws, r, 1, et, negrita=True, fondo=FONDO)
    celda(ws, r, 2, val)
    ws.merge_cells(start_row=r, start_column=2, end_row=r, end_column=6)
    ws.row_dimensions[r].height = max(18, lineas_est(val, sum(W[c] for c in (2, 3, 4, 5, 6))) * 13.4 + 5)
    r += 1

r += 1
banda(ws, r, "Conjuntos de anuncios (uno por región)", 6)
r += 1
encabezado(ws, r, ["Conjunto", "Centro de la zona", "Radio (millas)", "Presupuesto diario ($)", "Idioma", "Nota"])
r += 1
conjuntos = [
    ("Los Ángeles", "Los Ángeles, CA", 25, 15, "Español", "Cubre el centro, el este y el valle. Es la región más grande."),
    ("Orange County", "Santa Ana, CA", 20, 12, "Español", "Se traslapa un poco con Los Ángeles: no pasa nada."),
    ("Inland Empire", "San Bernardino, CA (+ otro punto en Riverside, CA, 20 mi)", 25, 12, "Español", "Dos puntos en el mismo conjunto: Meta deja agregar varios."),
]
primero = r
for nombre, centro, radio, pres, idioma, nota in conjuntos:
    celda(ws, r, 1, nombre, negrita=True)
    entrada(ws, r, 2, centro)
    entrada(ws, r, 3, radio)
    entrada(ws, r, 4, pres, DINERO0)
    celda(ws, r, 5, idioma)
    celda(ws, r, 6, nota, color=GRIS)
    ws.row_dimensions[r].height = max(30, lineas_est(centro, W[2]) * 13.4 + 5, lineas_est(nota, W[6]) * 13.4 + 5)
    r += 1
ultimo = r - 1
dv2 = DataValidation(type="decimal", operator="greaterThanOrEqual", formula1="15", allow_blank=False,
                     errorTitle="Radio mínimo", error="Meta pide al menos 15 millas en esta categoría.")
ws.add_data_validation(dv2)
dv2.add(f"C{primero}:C{ultimo}")
r += 1
banda(ws, r, "Cuánto cuesta (se calcula solo)", 6, color=AZUL)
r += 1
celda(ws, r, 1, "Primer día", negrita=True, fondo=FONDO)
c_ini = entrada(ws, r, 2, date(2026, 10, 14), FECHA)
fila_ini = r
r += 1
celda(ws, r, 1, "Último día (cierra el AEP)", negrita=True, fondo=FONDO)
entrada(ws, r, 2, date(2026, 12, 7), FECHA)
fila_fin = r
r += 1
celda(ws, r, 1, "Días corriendo", negrita=True, fondo=FONDO)
celda(ws, r, 2, f"=B{fila_fin}-B{fila_ini}+1", formato="0")
fila_dias = r
r += 1
celda(ws, r, 1, "Gasto por día (3 regiones)", negrita=True, fondo=FONDO)
celda(ws, r, 2, f"=SUM(D{primero}:D{ultimo})", formato=DINERO0)
fila_dia = r
r += 1
celda(ws, r, 1, "Gasto estimado hasta el 7 de dic", negrita=True, fondo=FONDO)
celda(ws, r, 2, f"=B{fila_dia}*B{fila_dias}", formato=DINERO0, negrita=True)
r += 1
celda(ws, r, 1, "Opción pequeña: solo Los Ángeles", negrita=True, fondo=FONDO)
celda(ws, r, 2, f"=D{primero}*B{fila_dias}", formato=DINERO0)
celda(ws, r, 3, "con el presupuesto de Los Ángeles de arriba; súbelo cuando veas que entran leads.", color=GRIS, borde=False)
ws.merge_cells(start_row=r, start_column=3, end_row=r, end_column=6)
r += 2
nota = ("Qué esperar: no sé todavía cuánto te costará cada lead. Como referencia general, los anuncios de clientes potenciales en Facebook de todas las industrias "
        "rondan los $27 por lead en 2026; Medicare y el español pueden ser distintos. Con 3–5 días de datos reales (hoja «Seguimiento») ya se puede calcular cuántas "
        "citas y aplicaciones salen por cada $100.")
celda(ws, r, 1, nota, color=GRIS, borde=False, italica=True)
ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=6)
ws.row_dimensions[r].height = max(30, lineas_est(nota, sum(W.values())) * 13.4 + 5)
imprimir(ws)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 3 · Anuncios
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_ANUN)
ws.sheet_properties.tabColor = DURAZNO
W = anchos(ws, [26, 100, 9, 9])
titulo(ws, "Anuncios · imagen, titulares y textos listos para copiar",
       "Cómo copiar un texto: selecciona las celdas de la columna B desde ▼ COPIA DESDE AQUÍ hasta ▲ HASTA AQUÍ, presiona Ctrl+C y pega en Meta con Ctrl+V. "
       "Cada línea está en su propia fila para que no salgan comillas. El aviso legal del final se completa solo con tus números de «Empieza aquí».", 4)
ws.row_dimensions[2].height = 42
banda(ws, 4, "Aviso legal (se agrega solo al final de cada texto)", 4)
celda(ws, 5, 1, "Licencia y no afiliación", negrita=True, fondo=FONDO)
celda(ws, 5, 2, LEGAL["licencia"])
ws.row_dimensions[5].height = lineas_est(LEGAL["licencia"], W[2]) * 13.4 + 5
celda(ws, 6, 1, "Aviso TPMO", negrita=True, fondo=FONDO)
celda(ws, 6, 2, TPMO_FORMULA)
ws.row_dimensions[6].height = lineas_est(TPMO_MUESTRA, W[2]) * 13.4 + 5
celda(ws, 7, 1, "Estado", negrita=True, fondo=FONDO)
celda(ws, 7, 2, f"={q(HOJA_EMPIEZA)}!C10", negrita=True)
ws.conditional_formatting.add("B7", FormulaRule(formula=[f'LEFT(B7,1)="⚠"'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.conditional_formatting.add("B7", FormulaRule(formula=[f'LEFT(B7,1)="✔"'], fill=cf(VERDE_FONDO), font=Font(bold=True, color=VERDE)))
banda(ws, 9, "Índice (haz clic para ir al anuncio)", 4, color=AZUL)
fila_indice = 10
fila = fila_indice + len(ANUNCIOS) + 1
inicios = {}
LARGO_OK = {}     # (fila, límite) para la regla de color

for i, a in enumerate(ANUNCIOS):
    inicios[a["id"]] = fila
    banda(ws, fila, f"ANUNCIO {i + 1} de {len(ANUNCIOS)} · {a['nombre']}", 4, color=NAVY, size=12, alto=26)
    fila += 1
    info = [
        ("Cuándo", f"Enciende el {corto(a['enciende'])} · se apaga el {corto(a['apaga'])} (11:59 pm) · {a['fase']}"),
        ("Por qué este anuncio", a["por_que"]),
        ("Imagen feed (4:5)", H_IMG(i, a, "feed")),
        ("Imagen historias y Reels (9:16)", H_IMG(i, a, "historia")),
        ("Texto dentro de la imagen", f"{a['imagen']['kicker']} · {a['imagen']['titulo'].replace('<em>', '').replace('</em>', '')} · {a['imagen']['sub']} · Botón de la imagen: {a['imagen']['cta']}"),
    ]
    for et, val in info:
        celda(ws, fila, 1, et, negrita=True, fondo=FONDO)
        celda(ws, fila, 2, val)
        ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=4)
        ws.row_dimensions[fila].height = max(18, lineas_est(val, W[2] + W[3] + W[4]) * 13.4 + 5)
        fila += 1
    encabezado(ws, fila, ["Campo en Meta", "Texto", "Largo", "Máximo"], color=AZUL)
    ws.row_dimensions[fila].height = 20
    fila += 1
    campos = [("Titular 1", a["titulares"][0], 40), ("Titular 2", a["titulares"][1], 40), ("Descripción", a["descripcion"], 30)]
    for et, val, maximo in campos:
        celda(ws, fila, 1, et, negrita=True, fondo=FONDO)
        celda(ws, fila, 2, val)
        celda(ws, fila, 3, f"=LEN(B{fila})", alinear=CENTRO)
        celda(ws, fila, 4, maximo, alinear=CENTRO, color=GRIS)
        LARGO_OK[fila] = maximo
        ws.row_dimensions[fila].height = 18
        fila += 1
    celda(ws, fila, 1, "Botón", negrita=True, fondo=FONDO)
    celda(ws, fila, 2, a["boton"])
    ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=4)
    ws.row_dimensions[fila].height = 18
    fila += 1
    for var in ("A", "B"):
        banda(ws, fila, f"TEXTO PRINCIPAL {var} (se sube como una de las opciones de texto del mismo anuncio)", 4, color=CIELO, fg=NAVY, size=10, alto=20)
        fila += 1
        lineas = a["textos"][var] + ["", "@LICENCIA", "@TPMO"]
        for k, ln in enumerate(lineas):
            if ln == "@LICENCIA":
                v = "=$B$5"
                est = LEGAL["licencia"]
            elif ln == "@TPMO":
                v = "=$B$6"
                est = TPMO_MUESTRA
            else:
                v = ln if ln else None
                est = ln
            c = celda(ws, fila, 2, v, borde=False)
            c.border = Border(left=fino, right=fino, top=fino if k == 0 else None, bottom=fino if k == len(lineas) - 1 else None)
            if k == 0:
                celda(ws, fila, 1, "▼ COPIA DESDE AQUÍ", negrita=True, color=DURAZNO if False else "B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
            if k == len(lineas) - 1:
                celda(ws, fila, 1, "▲ HASTA AQUÍ", negrita=True, color="B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
            ws.row_dimensions[fila].height = max(15, lineas_est(est or "", W[2]) * 13.4 + 3)
            fila += 1
        # largo del texto con el aviso (informativo)
        celda(ws, fila, 1, f"Largo del texto {var}", color=GRIS, borde=False, size=9, alinear=Alignment(horizontal="right"))
        largo = sum(len(x) + 1 for x in a["textos"][var]) + len(LEGAL["licencia"]) + len(TPMO_MUESTRA) + 2
        celda(ws, fila, 2, f"{largo} caracteres con el aviso legal (Meta permite hasta 2,200; lo importante está en los primeros 125).", color=GRIS, borde=False, size=9)
        ws.row_dimensions[fila].height = 14
        fila += 1
    fila += 1

# regla de color para largos
for f_, mx in LARGO_OK.items():
    ws.conditional_formatting.add(f"C{f_}", CellIsRule(operator="greaterThan", formula=[str(mx)], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
for i, a in enumerate(ANUNCIOS):
    vinculo(celda(ws, fila_indice + i, 1, ""), f"{q(HOJA_ANUN)}!A{inicios[a['id']]}", f"Anuncio {i + 1}")
    celda(ws, fila_indice + i, 2, f"{a['nombre']} · enciende el {corto(a['enciende'])}")
    ws.merge_cells(start_row=fila_indice + i, start_column=2, end_row=fila_indice + i, end_column=4)
imprimir(ws, horizontal=False)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 4 · Formulario
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_FORM)
ws.sheet_properties.tabColor = AZUL
W = anchos(ws, [30, 100, 20])
titulo(ws, "Formulario instantáneo (Meta)", "Se crea una sola vez y se usa en todos los anuncios. Sin datos sensibles: Meta no los permite y CMS pide cuidado con los datos de salud.", 3)
ws.row_dimensions[2].height = 30
fila = 4
banda(ws, fila, "Datos del formulario", 3)
fila += 1
formulario = [
    ("Tipo", "Mayor intención (agrega una pantalla para revisar antes de enviar: llegan menos leads, pero más serios)"),
    ("Nombre interno", "AEP 2026 · Revisión de plan · español"),
    ("Título", "Pide tu revisión de Medicare en español"),
    ("Introducción", "Déjame tus datos y te llamo para explicarte tus opciones de Medicare, en español y sin compromiso."),
    ("Campos de siempre (Meta los llena del perfil)", "Nombre completo · Número de teléfono · Código postal"),
    ("Pregunta 1 (opción múltiple)", "¿Qué te gustaría revisar?  →  Mi plan para 2027  /  Doctores y medicinas de mi plan  /  Mi primer Medicare  /  Ayudar a un familiar"),
    ("Pregunta 2 (opción múltiple)", "¿Cómo prefieres que te contacte?  →  Llamada  /  Mensaje de texto"),
]
for et, val in formulario:
    celda(ws, fila, 1, et, negrita=True, fondo=FONDO)
    celda(ws, fila, 2, val)
    ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
    ws.row_dimensions[fila].height = max(18, lineas_est(val, W[2] + W[3]) * 13.4 + 5)
    fila += 1
fila += 1
banda(ws, fila, "Permiso para contactarte (aviso legal personalizado de Meta)", 3, color=AZUL)
fila += 1
celda(ws, fila, 1, "Título del aviso", negrita=True, fondo=FONDO)
celda(ws, fila, 2, "Permiso para contactarte")
ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
fila += 1
celda(ws, fila, 1, "Casilla de aceptación", negrita=True, fondo=FONDO)
celda(ws, fila, 2, "Acepto (obligatoria)")
ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
fila += 1
celda(ws, fila, 1, "Texto del aviso: copia desde ▼ hasta ▲", negrita=True, borde=False, color=GRIS)
ws.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=3)
fila += 1
permiso = [
    "Al enviar este formulario, autorizo a Isabel Fuentes, agente de seguros con licencia en California (#0D96598), a comunicarse conmigo por llamada o mensaje de texto, incluidos mensajes automáticos, al número que escribí, para hablar sobre opciones de Medicare, incluidos planes Medicare Advantage y de medicinas.",
    "Esto no me inscribe en ningún plan ni me obliga a comprar nada. Pueden aplicar tarifas de mensajes y datos. Puedo pedir que dejen de contactarme en cualquier momento.",
    "@LICENCIA",
    "@TPMO",
]
for k, ln in enumerate(permiso):
    if ln == "@LICENCIA":
        v, est = f"={q(HOJA_ANUN)}!$B$5", LEGAL["licencia"]
    elif ln == "@TPMO":
        v, est = f"={q(HOJA_ANUN)}!$B$6", TPMO_MUESTRA
    else:
        v, est = (ln or None), ln
    c = celda(ws, fila, 2, v, borde=False)
    c.border = Border(left=fino, right=fino, top=fino if k == 0 else None, bottom=fino if k == len(permiso) - 1 else None)
    if k == 0:
        celda(ws, fila, 1, "▼ COPIA DESDE AQUÍ", negrita=True, color="B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
    if k == len(permiso) - 1:
        celda(ws, fila, 1, "▲ HASTA AQUÍ", negrita=True, color="B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
    ws.row_dimensions[fila].height = max(15, lineas_est(est or "", W[2]) * 13.4 + 3)
    fila += 1
fila += 1
celda(ws, fila, 1, "Enlace de privacidad", negrita=True, fondo=FONDO)
celda(ws, fila, 2, f'=IF({REF_PRIV}="","⚠ Falta el enlace: escríbelo en «Empieza aquí»",{REF_PRIV})')
ws.conditional_formatting.add(f"B{fila}", FormulaRule(formula=[f'LEFT(B{fila},1)="⚠"'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
fila += 1
celda(ws, fila, 1, "Texto del enlace", negrita=True, fondo=FONDO)
celda(ws, fila, 2, "Política de privacidad")
ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
fila += 2
banda(ws, fila, "Pantalla de gracias", 3, color=AZUL)
fila += 1
gracias = [
    ("Título", "¡Gracias!"),
    ("Texto", "Recibí tus datos. Te llamo pronto desde el +1 (310) 270-0626. Si prefieres, llámame tú ahora."),
    ("Botón 1", "Llamar a Isabel  →  +1 (310) 270-0626"),
    ("Botón 2", "Ver mi sitio  →  withisabelfuentes.com"),
]
for et, val in gracias:
    celda(ws, fila, 1, et, negrita=True, fondo=FONDO)
    celda(ws, fila, 2, val)
    ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
    ws.row_dimensions[fila].height = max(18, lineas_est(val, W[2] + W[3]) * 13.4 + 5)
    fila += 1
fila += 1
banda(ws, fila, "Lo que NO se pregunta en el formulario", 3, color=ROJO)
fila += 1
no_preguntar = ("Número de Medicare · fecha de nacimiento · condiciones de salud · medicinas · nombre de tu doctor · plan o aseguradora actual · ingresos. "
                "Meta los trata como información sensible y no permite pedirlos en formularios; además, hablar de beneficios o de planes antes de firmar el SOA no está permitido. "
                "Eso se pregunta en la llamada, después del aviso TPMO y del SOA. (El borrador de la app preguntaba «qué tiene hoy»: se quitó por esta razón.)")
celda(ws, fila, 1, no_preguntar, borde=False)
ws.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=3)
ws.row_dimensions[fila].height = lineas_est(no_preguntar, sum(W.values())) * 13.4 + 6
fila += 2
banda(ws, fila, "Primer mensaje de texto (solo a quien dejó sus datos y no contestó) · copia desde ▼ hasta ▲", 3, color=AZUL)
fila += 1
sms = [
    "Hola [nombre], soy Isabel Fuentes, agente de seguros con licencia en California. Recibí tu solicitud para revisar Medicare. ¿Te puedo llamar hoy? Responde SÍ o dime a qué hora te conviene.",
    "Responde STOP para no recibir más mensajes.",
]
for k, ln in enumerate(sms):
    c = celda(ws, fila, 2, ln, borde=False)
    c.border = Border(left=fino, right=fino, top=fino if k == 0 else None, bottom=fino if k == len(sms) - 1 else None)
    if k == 0:
        celda(ws, fila, 1, "▼ COPIA DESDE AQUÍ", negrita=True, color="B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
    if k == len(sms) - 1:
        celda(ws, fila, 1, "▲ HASTA AQUÍ", negrita=True, color="B5651D", borde=False, alinear=Alignment(horizontal="right", vertical="top"))
    ws.row_dimensions[fila].height = max(15, lineas_est(ln, W[2]) * 13.4 + 3)
    fila += 1
imprimir(ws, horizontal=False)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 5 · Semana a semana
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_SEM)
ws.sheet_properties.tabColor = AZUL
W = anchos(ws, [18, 24, 54, 54, 54])
titulo(ws, "Semana a semana · qué enciendes y qué revisas",
       "Sigue el plan AEP 2026 (Meta 300). Cada semana se suma un anuncio nuevo; los que van caros se apagan. Todos los lunes llenas «Seguimiento» (10 minutos).", 5)
ws.row_dimensions[2].height = 30
encabezado(ws, 4, ["Fecha", "Fase del plan", "Qué haces en Meta", "Qué miras", "Si pasa esto… haz esto"])
ano = lambda iso: corto(iso)
semanas = [
    ("2026-10-12", "Llenar el pipeline", "Enviar a revisión los anuncios 1, 2 y 3 (uno por región: 3 conjuntos × 3 anuncios). Programa inicio el miércoles 14 y fin el 7 de dic.",
     "Que Meta los apruebe (hasta ~1 día). Que el formulario y la política de privacidad abran bien.", "Si rechazan uno: hoja «Cómo subirlo», parte «Si Meta rechaza un anuncio»."),
    ("2026-10-15", "Semana 1 · Arranque", "Nada nuevo. Es el día que abre el AEP: mira que los anuncios estén corriendo.",
     "Que lleguen leads y que los llames en la primera hora.", "Primeros 3 días: no cambies nada (Meta está aprendiendo)."),
    ("2026-10-19", "Semana 1 · Arranque", "Primer lunes: llena «Seguimiento» (gasto, leads, citas, aplicaciones).",
     "Costo por lead de cada anuncio (en Meta, columna «Costo por cliente potencial»).", "Un anuncio con $50 gastados y 0 leads → apágalo."),
    ("2026-10-22", "Semana 2 · Medicinas", "Enciende el anuncio 4 (lista de medicinas) en los 3 conjuntos.",
     "Cuál de los 4 trae leads más baratos.", "Si uno cuesta más del doble que el mejor y ya gastó $50 → apágalo."),
    ("2026-10-29", "Semana 3 · Beneficios extra", "Enciende el anuncio 5 (beneficios extra, según el plan).",
     "Leads por región: ¿Los Ángeles, Orange o Inland Empire traen más?", "Si una región no da leads en una semana, baja su presupuesto a la mitad y sube la que sí."),
    ("2026-11-05", "Semana 4 · Doctores y red", "Enciende el anuncio 6 (¿tu doctor está en la red?). El plan pide apagar el anuncio más caro.",
     "Mitad del AEP: aplicaciones acumuladas contra la meta del plan (156).", "Si vas en rojo, sube el presupuesto de lo que mejor funciona (no el de todo)."),
    ("2026-11-12", "Semana 5 · Historias y referidos", "Enciende el anuncio 7 (referidos). Sin regalos ni premios.",
     "Compartidos y comentarios además de leads.", "Los testimonios solo se usan si son reales y con permiso por escrito."),
    ("2026-11-19", "Semana 6 · Antes de Thanksgiving", "Enciende el anuncio 8 (Medicare en familia).",
     "Leads que vienen de hijos y cuidadores: agenda la cita con el papá o la mamá.", "El permiso para llamar y el SOA siempre son de la persona con Medicare."),
    ("2026-11-26", "Semana 7 · Thanksgiving", "Jueves 26: baja el presupuesto a la mitad (día libre). El viernes vuelve a lo normal.",
     "Leads que llegaron el jueves: llámalos el viernes.", "—"),
    ("2026-11-30", "Semana 7 · Quedan días", "Enciende el anuncio 9 (últimos días). Deja encendidos solo los 3 mejores.",
     "Costo por lead de los últimos 7 días.", "Si todo cuesta más de lo que aceptas, cambia imagen o texto A/B antes de subir presupuesto."),
    ("2026-12-03", "Cierre AEP", "Sin cambios grandes. Revisa dos veces al día los leads nuevos.",
     "Leads sin llamar: ninguno debe quedar sin intento.", "Aplicaciones recibidas el 7 de dic cuentan; documenta todo."),
    ("2026-12-07", "Cierre AEP · último día", "Los anuncios terminan a las 11:59 pm (ya programado).",
     "Que se apaguen solos.", "Si alguno sigue prendido, apágalo a mano."),
    ("2026-12-08", "Post-AEP", "Comprueba que todo está apagado. Descarga los leads del mes (CSV) y guárdalos.",
     "Aplicaciones vs efectivas por fuente: ¿Facebook valió la pena?", "Captura de pantalla de cada anuncio publicado, guardada en una carpeta «Anuncios AEP 2026»."),
]
fila = 5
for iso, fase, hacer, mirar, si in semanas:
    celda(ws, fila, 1, corto(iso), negrita=True, fondo=FONDO)
    celda(ws, fila, 2, fase)
    celda(ws, fila, 3, hacer)
    celda(ws, fila, 4, mirar)
    celda(ws, fila, 5, si, color=GRIS)
    altura(ws, fila, W)
    fila += 1
fila += 1
banda(ws, fila, "Cuatro reglas para decidir (sin adivinar)", 5, color=AZUL)
fila += 1
reglas = [
    "1. Días 1–3: no toques nada. Meta tarda unos días en aprender a quién mostrarle el anuncio.",
    "2. Un anuncio con $50 gastados y 0 leads se apaga. Uno con costo por lead de más del doble que el mejor, también.",
    "3. Muchos leads pero pocas citas: el problema no es el anuncio, es la velocidad. Llama en la primera hora (entre 8 am y 9 pm, hora del cliente).",
    "4. Cambia una sola cosa a la vez (la imagen, o el texto A/B). Así sabes qué funcionó.",
]
for rg in reglas:
    celda(ws, fila, 1, rg, borde=False)
    ws.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=5)
    ws.row_dimensions[fila].height = 20
    fila += 1
imprimir(ws)
ws.freeze_panes = "A5"
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 6 · Cumplimiento
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_CUMP)
ws.sheet_properties.tabColor = ROJO
W = anchos(ws, [8, 62, 66])
titulo(ws, "Cumplimiento · revisa esto antes de publicar",
       "Reglas de CMS (plan 2027) y de Meta. Tu FMO tiene la última palabra; si algo de aquí choca con su proceso, gana su proceso.", 3)
ws.row_dimensions[2].height = 30
dvc = DataValidation(type="list", formula1='"☐,✔"', allow_blank=True)
ws.add_data_validation(dvc)


def lista_check(fila, items, color=NAVY, nombre=""):
    banda(ws, fila, nombre, 3, color=color)
    fila += 1
    encabezado(ws, fila, ["✔", "Revisa", "Por qué"], color=AZUL)
    fila += 1
    for texto, por_que in items:
        celda(ws, fila, 1, "☐", alinear=CENTRO, size=13)
        dvc.add(f"A{fila}")
        celda(ws, fila, 2, texto, negrita=True)
        celda(ws, fila, 3, por_que, color=GRIS)
        altura(ws, fila, W)
        fila += 1
    return fila + 1


fila = 4
fila = lista_check(fila, [
    ("Cada anuncio lleva el aviso TPMO completo, con tus 2 números, y la licencia.", "CMS: el aviso va en el anuncio mismo, no solo en la página a la que lleva. Ya viene al final de cada texto."),
    ("Nada de «gratis», «el mejor», «garantizado», «lo más barato», «oferta exclusiva», «sin costo alguno».", "Son las frases de alerta de tu sistema (también se revisan en la app con «Revisor»)."),
    ("Nada de comparar con otras aseguradoras por nombre ni de hablar mal de ellas.", "CMS prohíbe la comparación negativa."),
    ("Sin logos ni nombre del gobierno, ni la tarjeta de Medicare.", "Evita que parezca oficial. Tu pie de imagen ya dice «no afiliada ni respaldada»."),
    ("Los beneficios siempre «según el plan y el área». Sin cifras de un plan en los anuncios generales.", "Los anuncios con cifras de un plan pasan primero por el carrier/FMO (hoja «Planes»)."),
    ("Las fechas son reales: AEP del 15 de oct al 7 de dic. Sin «última oportunidad» fuera de esos días.", "La urgencia tiene que ser verdadera y sin presión."),
    ("Los anuncios se apagan el 7 de dic y se comprueba el 8.", "Después del 7 de dic ya no se puede cambiar de plan por el AEP."),
    ("Guarda una captura de pantalla de cada anuncio publicado, con fecha.", "Por si tu FMO o un carrier la pide."),
    ("Si tu FMO exige el aviso TPMO completo (con tus números) dentro de la imagen, pide las imágenes de nuevo.", "Las imágenes llevan el pie corto (licencia, «no ofrecemos todos los planes», Medicare.gov y 1-800-MEDICARE); el aviso completo con tus números va en el texto del anuncio."),
], color=NAVY, nombre="A · CMS y tu agencia")
fila = lista_check(fila, [
    ("«Productos y servicios financieros» está marcado en la campaña.", "Meta lo exige para anuncios de seguros en EE. UU.; sin eso pueden rechazar el anuncio o restringir la cuenta."),
    ("Ningún texto afirma ni insinúa la edad, la salud, la situación económica o el origen de quien lo ve.", "Meta lo prohíbe («¿Cumples 65?», «¿Tienes diabetes?», «Si tienes Medi-Cal…»). Se habla del tema, no de la persona."),
    ("El formulario no pide número de Medicare, nacimiento, salud, medicinas, doctor, plan actual ni ingresos.", "Meta los trata como información sensible."),
    ("El enlace de privacidad abre una página web.", "Meta no acepta un PDF ni una descarga."),
    ("La imagen lleva el pie legal completo y se ve con claridad en el celular.", "Ya viene incluido: revísalo en una vista previa de Meta antes de publicar."),
], color=AZUL, nombre="B · Meta (Facebook e Instagram)")

banda(ws, fila, "C · En vez de esto… usa esto", 3, color=NAVY)
fila += 1
encabezado(ws, fila, ["", "En vez de…", "Usa…"], color=AZUL)
fila += 1
cambios = [
    ("«¿Cumples 65?»", "«Medicare para quienes están por cumplir 65 años»"),
    ("«¿Tomas medicinas?», «¿Tienes diabetes?»", "«Revisa la lista de medicinas de tu plan»"),
    ("«Si tienes Medi-Cal…»", "«Planes para personas con Medicare y Medi-Cal» (solo con aprobación del carrier y hablando del plan, no de la persona)"),
    ("«Gratis», «$0 sin costo alguno»", "«$0 de prima mensual del plan, según el plan y el área» (solo en anuncios de plan aprobados)"),
    ("«El mejor plan», «el más barato»", "«Te explico las opciones que ofrezco en tu área»"),
    ("«Garantizado», «sin letra chiquita»", "«Depende del plan» · «te explico hasta la letra chiquita»"),
    ("«Última oportunidad» (fuera de fechas)", "«El 7 de diciembre termina la inscripción abierta» (solo del 30 de nov al 7 de dic)"),
    ("«No pierdas tus beneficios»", "«Revisa qué cambia en tu plan para 2027»"),
    ("«Mejor que [aseguradora]»", "No comparar con nombres."),
]
for malo, bueno in cambios:
    celda(ws, fila, 1, "", borde=True)
    celda(ws, fila, 2, malo, color=ROJO)
    celda(ws, fila, 3, bueno, color=VERDE)
    altura(ws, fila, W)
    fila += 1
fila += 1
banda(ws, fila, "D · Cuando llega un lead", 3, color=NAVY)
fila += 1
leads = [
    "Llámalo en la primera hora, entre 8 am y 9 pm hora del cliente. Si no contesta, manda el mensaje de la hoja «Formulario». Máximo 3 intentos en 3 días; confirma con tu FMO cuántos permite su política.",
    "Primeros segundos de la llamada: quién eres (agente con licencia) → que la llamada se graba → el aviso TPMO completo, antes de hablar de cualquier beneficio.",
    "Antes de hablar de planes concretos, firma el SOA. CMS 2027 ya no exige esperar 48 horas, pero algunos carriers o FMO sí lo piden: confírmalo con tu FMO.",
    "Guarda las grabaciones de las llamadas de marketing y ventas por 6 años.",
    "Si el lead es un hijo o hija que pide ayuda para sus papás: agenda la cita con el beneficiario. El permiso para contactarle y el SOA son de la persona con Medicare.",
    "No compres ni compartas listas de leads. Solo se llama a quien dejó sus datos en tu formulario.",
]
for t in leads:
    celda(ws, fila, 1, "•", alinear=CENTRO, borde=False)
    celda(ws, fila, 2, t, borde=False)
    ws.merge_cells(start_row=fila, start_column=2, end_row=fila, end_column=3)
    ws.row_dimensions[fila].height = max(18, lineas_est(t, W[2] + W[3]) * 13.4 + 5)
    fila += 1
fila += 1
banda(ws, fila, "E · Lo que no pude verificar", 3, color=GRIS)
fila += 1
no_ver = ("Las reglas de Meta (categoría especial, atributos personales, formularios) las leí en resúmenes de terceros y en buscadores: la página oficial de Meta no se abre desde donde trabajo. "
          "Las reglas de CMS 2027 son las investigadas el 9–10 de oct de 2026 y las que ya usa tu sistema. Confirma todo con tu FMO y con el Administrador de anuncios al crear la campaña.")
celda(ws, fila, 1, no_ver, borde=False, color=GRIS, italica=True)
ws.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=3)
ws.row_dimensions[fila].height = lineas_est(no_ver, sum(W.values())) * 13.4 + 6
imprimir(ws, horizontal=False)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 7 · Planes (con aprobación)
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_PLAN)
ws.sheet_properties.tabColor = "B42318"
W = anchos(ws, [16, 34, 14, 58, 58, 52, 22])
titulo(ws, "Planes concretos · ideas para el carrier o el FMO", None, 7)
banda(ws, 2, "NO PUBLICAR. Estas ideas usan cifras de planes 2027: antes de publicarlas el carrier o tu FMO tiene que aprobar el texto, y tienes que estar contratada con ese carrier para 2027.", 7, color="B42318", size=11, alto=34)
nota = ("Cifras tomadas de tu archivo «2027 Plan Comparison – All Carriers» (impreso el 9 de oct de 2026). Los IDs marcados «verificar» fueron inferidos de códigos de impresión y hay que confirmarlos antes de usarlos. "
        "No incluí los planes de UnitedHealthcare/AARP: esa información viene de un cuadro marcado «solo para uso de agentes», no para el público. "
        "Un anuncio de un plan lleva los avisos oficiales del carrier (nombre del plan, «plan HMO con contrato de Medicare», limitaciones y copagos…): no los inventes, pídelos.")
celda(ws, 3, 1, nota, borde=False, color=GRIS, italica=True)
ws.merge_cells("A3:G3")
ws.row_dimensions[3].height = lineas_est(nota, sum(W.values())) * 13.4 + 6
encabezado(ws, 5, ["Carrier", "Plan · ID", "Condados", "Datos del plan (de tu archivo)", "Idea de anuncio (borrador, NO publicar)", "Qué exige / riesgo", "Estado"], color="B42318")
planes = [
    ("Humana", "Gold Plus Giveback (HMO)\nH5619-146-000", "LA, OC",
     "Prima del plan $0 · reducción de la prima de la Parte B $84/mes · máximo de gastos de bolsillo $2,700 · dental $1,000/año (50% en servicios mayores) · sin OTC ni transporte",
     "«Un plan HMO que puede reducir tu prima de la Parte B hasta $84 al mes, según el plan y el área.» + [AVISO DEL CARRIER]",
     "Hay que seguir pagando la prima de la Parte B: va en el aviso del carrier. «Reducción de la prima de la Parte B» es el término correcto (no «te regresan dinero»)."),
    ("SCAN", "Allied (HMO)\nH5425-123-000 · verificar", "LA",
     "Prima $0 · reducción de la prima de la Parte B $125/mes · máximo de gastos de bolsillo $2,000 · dental hasta $1,000/año · tarjeta flexible $65/mes (venta libre; comida solo para miembros elegibles) · 12 viajes",
     "«Plan HMO en Los Ángeles con reducción de la prima de la Parte B de hasta $125 al mes y tarjeta para productos de venta libre, según el plan.» + [AVISO DEL CARRIER]",
     "Plan solo del condado de Los Ángeles. ID por verificar. Lo de «comida» aplica solo a quien califica (enfermedad crónica): no ponerlo como beneficio general."),
    ("SCAN", "Essential Savings (HMO)\nH5425-133-000 · verificar", "LA",
     "Prima $0 · reducción de la prima de la Parte B $185/mes (la más alta del archivo) · máximo de gastos de bolsillo $2,400 · especialista $15 · visión, audífonos, OTC y transporte: no aparecen en el folleto",
     "«Reducción de la prima de la Parte B de hasta $185 al mes en un plan HMO de Los Ángeles, según el plan.» + [AVISO DEL CARRIER]",
     "Es un plan básico: no lo presentes como completo. Confirma con SCAN qué beneficios tiene antes de decir nada más que la reducción de la prima."),
    ("Anthem", "Select (HMO-POS)\nH0544-058-000", "LA, OC",
     "Prima $0 · máximo de gastos de bolsillo $800 · audífonos hasta $3,000/año (o $300 si son de venta libre) · dental $500/año · OTC $32/trimestre · 2 viajes/año · medicinas con coseguro (deducible $200)",
     "«Plan HMO-POS con máximo de gastos de bolsillo de $800 y apoyo para audífonos, según el plan y el área.» + [AVISO DEL CARRIER]",
     "Es nuevo para 2027. Dental limitado y medicinas con coseguro: si destacas los audífonos, no ocultes eso."),
    ("Humana", "Gold Plus (HMO)\nH3767-002-000", "LA, OC",
     "Prima $0 · máximo de gastos de bolsillo $999 · dental $3,000/año (30% en servicios mayores) · OTC $50/trimestre · 24 viajes/año · audífonos $575 / $750",
     "«Plan HMO con $3,000 al año para dental (con coseguro en servicios mayores) y tarjeta de venta libre trimestral, según el plan y el área.» + [AVISO DEL CARRIER]",
     "El plan Gold Plus H5619-021 (máximo $799, OTC $55) es parecido: pide a Humana cuál están promoviendo para 2027."),
    ("SCAN", "Costco Medicare Advantage (HMO)\nH5425-141 (OC) · H5425-142 (LA)", "OC, LA",
     "Prima $0 · máximo de gastos de bolsillo $299 · dental hasta $2,000/año · tarjeta $115/trimestre (venta libre; comida solo si califica) · medicinas por la red de farmacias de Costco",
     "«Plan HMO de SCAN con máximo de gastos de bolsillo de $299 y dental hasta $2,000 al año, según el plan y el área.» + [AVISO DEL CARRIER]",
     "Usar el nombre «Costco» necesita permiso de SCAN/Costco. Confirma si exige membresía de Costco. El plan de San Diego aún está tomado de una foto: no usarlo."),
    ("L.A. Care", "Medicare Plus (HMO D-SNP)\nH1224-001-000 · verificar", "LA",
     "Para quienes tienen Medicare y Medi-Cal. Prima, deducible y gastos de bolsillo $0 · tarjeta flexible $120/mes (venta libre; comida, servicios y gasolina solo si califica) · viajes ilimitados",
     "Solo en la voz del plan, no de la persona: «Plan para personas con Medicare y Medi-Cal en el condado de Los Ángeles.» + [AVISO DEL CARRIER]",
     "Meta no permite insinuar la situación económica del lector: nunca «si tienes Medi-Cal…». Requiere elegibilidad de Medi-Cal. Confirma con el plan qué se puede decir."),
    ("SCAN", "Connections (HMO D-SNP)\nH0976-001-000 · verificar", "LA (no OC)",
     "Para quienes tienen Medicare y Medi-Cal. Prima y gastos de bolsillo $0 · dental $0 hasta $5,000/año (incluye implantes) · tarjeta flexible $110/mes · viajes ilimitados · cubre LA, Riverside, San Bernardino y San Diego (no OC)",
     "Igual que la anterior: describir el plan, no a la persona. + [AVISO DEL CARRIER]",
     "Mismas reglas de Meta y del plan que L.A. Care. Verificar el ID."),
]
fila = 6
estados = ["Pendiente de aprobación", "Aprobado por carrier/FMO", "Descartado"]
dvp = DataValidation(type="list", formula1='"' + ",".join(estados) + '"', allow_blank=False)
ws.add_data_validation(dvp)
for carrier, plan, cond, datos, idea, riesgo in planes:
    celda(ws, fila, 1, carrier, negrita=True, fondo=FONDO)
    celda(ws, fila, 2, plan, negrita=True)
    celda(ws, fila, 3, cond, alinear=CENTRO)
    celda(ws, fila, 4, datos)
    celda(ws, fila, 5, idea, color=GRIS)
    celda(ws, fila, 6, riesgo, color=ROJO)
    entrada(ws, fila, 7, estados[0])
    ws.cell(row=fila, column=7).alignment = CENTRO
    dvp.add(f"G{fila}")
    altura(ws, fila, W)
    fila += 1
ws.conditional_formatting.add(f"G6:G{fila - 1}", FormulaRule(formula=['G6="Aprobado por carrier/FMO"'], fill=cf(VERDE_FONDO), font=Font(bold=True, color=VERDE)))
ws.conditional_formatting.add(f"G6:G{fila - 1}", FormulaRule(formula=['G6="Descartado"'], fill=cf("F3F4F6"), font=Font(color=GRIS)))
fila += 1
cuando = ("Cuando un carrier o tu FMO apruebe una idea: cámbiale el estado a «Aprobado por carrier/FMO», mándame el texto exacto que aprobaron (con sus avisos) y preparo la imagen y el anuncio completo. "
          "Hasta entonces, los 9 anuncios de la hoja «Anuncios» son los únicos que se pueden publicar.")
celda(ws, fila, 1, cuando, borde=False, negrita=True)
ws.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=7)
ws.row_dimensions[fila].height = lineas_est(cuando, sum(W.values())) * 13.4 + 6
ws.freeze_panes = "A6"
imprimir(ws)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 8 · Seguimiento
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_SEG)
ws.sheet_properties.tabColor = VERDE
W = anchos(ws, [22, 20, 14, 12, 12, 14, 16, 16, 18, 14])
titulo(ws, "Seguimiento · tus números cada lunes",
       "Anota solo lo amarillo. Gasto y leads salen del Administrador de anuncios de Meta; citas y aplicaciones, de tu CRM. Lo demás se calcula solo.", 10)
ws.row_dimensions[2].height = 30
celda(ws, 3, 1, "Costo máximo por lead que aceptas (se escribe en «Empieza aquí»)", negrita=True, borde=False, color=GRIS, size=9)
ws.merge_cells("A3:B3")
celda(ws, 3, 3, f'=IF({REF_CPL}="","",{REF_CPL})', formato=DINERO, negrita=True)
ws.row_dimensions[3].height = 28
REF_CPL_LOCAL = "$C$3"
encabezado(ws, 4, ["Semana", "Fechas", "Gasto ($)", "Leads", "Citas", "Aplicaciones", "Costo por lead", "Costo por cita", "Costo por aplicación", "Leads → citas"])
semanas_seg = [
    ("Semana 1", "14–21 oct"), ("Semana 2", "22–28 oct"), ("Semana 3", "29 oct–4 nov"), ("Semana 4", "5–11 nov"),
    ("Semana 5", "12–18 nov"), ("Semana 6", "19–25 nov"), ("Semana 7", "26 nov–2 dic"), ("Cierre", "3–7 dic"),
]
f0 = 5
for k, (sem, fechas_) in enumerate(semanas_seg):
    f_ = f0 + k
    celda(ws, f_, 1, sem, negrita=True, fondo=FONDO)
    celda(ws, f_, 2, fechas_)
    entrada(ws, f_, 3, None, DINERO)
    entrada(ws, f_, 4, None)
    entrada(ws, f_, 5, None)
    entrada(ws, f_, 6, None)
    celda(ws, f_, 7, f'=IFERROR(IF(D{f_}=0,"",C{f_}/D{f_}),"")', formato=DINERO)
    celda(ws, f_, 8, f'=IFERROR(IF(E{f_}=0,"",C{f_}/E{f_}),"")', formato=DINERO)
    celda(ws, f_, 9, f'=IFERROR(IF(F{f_}=0,"",C{f_}/F{f_}),"")', formato=DINERO)
    celda(ws, f_, 10, f'=IFERROR(IF(D{f_}=0,"",E{f_}/D{f_}),"")', formato="0%")
    ws.row_dimensions[f_].height = 20
fl = f0 + len(semanas_seg) - 1
ft = fl + 1
celda(ws, ft, 1, "TOTAL", negrita=True, fondo=CIELO)
celda(ws, ft, 2, "", fondo=CIELO)
for col in (3, 4, 5, 6):
    L = get_column_letter(col)
    celda(ws, ft, col, f"=SUM({L}{f0}:{L}{fl})", negrita=True, fondo=CIELO, formato=DINERO if col == 3 else "0")
celda(ws, ft, 7, f'=IFERROR(IF(D{ft}=0,"",C{ft}/D{ft}),"")', negrita=True, fondo=CIELO, formato=DINERO)
celda(ws, ft, 8, f'=IFERROR(IF(E{ft}=0,"",C{ft}/E{ft}),"")', negrita=True, fondo=CIELO, formato=DINERO)
celda(ws, ft, 9, f'=IFERROR(IF(F{ft}=0,"",C{ft}/F{ft}),"")', negrita=True, fondo=CIELO, formato=DINERO)
celda(ws, ft, 10, f'=IFERROR(IF(D{ft}=0,"",E{ft}/D{ft}),"")', negrita=True, fondo=CIELO, formato="0%")
ws.row_dimensions[ft].height = 22
# costo por lead: rojo si pasa lo que aceptas, verde si no
ws.conditional_formatting.add(f"G{f0}:G{ft}", FormulaRule(formula=[f'AND(ISNUMBER(G{f0}),ISNUMBER({REF_CPL_LOCAL}),G{f0}>{REF_CPL_LOCAL})'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.conditional_formatting.add(f"G{f0}:G{ft}", FormulaRule(formula=[f'AND(ISNUMBER(G{f0}),ISNUMBER({REF_CPL_LOCAL}),G{f0}<={REF_CPL_LOCAL})'], fill=cf(VERDE_FONDO), font=Font(bold=True, color=VERDE)))

fr = ft + 2
banda(ws, fr, "Por región (acumulado)", 10, color=AZUL)
fr += 1
encabezado(ws, fr, ["Región", "", "Gasto ($)", "Leads", "Citas", "Aplicaciones", "Costo por lead", "Costo por cita", "Costo por aplicación", ""], color=AZUL)
fr += 1
r0 = fr
for reg in ("Los Ángeles", "Orange County", "Inland Empire"):
    celda(ws, fr, 1, reg, negrita=True, fondo=FONDO)
    celda(ws, fr, 2, "")
    entrada(ws, fr, 3, None, DINERO)
    entrada(ws, fr, 4, None)
    entrada(ws, fr, 5, None)
    entrada(ws, fr, 6, None)
    celda(ws, fr, 7, f'=IFERROR(IF(D{fr}=0,"",C{fr}/D{fr}),"")', formato=DINERO)
    celda(ws, fr, 8, f'=IFERROR(IF(E{fr}=0,"",C{fr}/E{fr}),"")', formato=DINERO)
    celda(ws, fr, 9, f'=IFERROR(IF(F{fr}=0,"",C{fr}/F{fr}),"")', formato=DINERO)
    celda(ws, fr, 10, "")
    ws.row_dimensions[fr].height = 20
    fr += 1
ws.conditional_formatting.add(f"G{r0}:G{fr - 1}", FormulaRule(formula=[f'AND(ISNUMBER(G{r0}),ISNUMBER({REF_CPL_LOCAL}),G{r0}>{REF_CPL_LOCAL})'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
fr += 1
banda(ws, fr, "Por anuncio (acumulado) · decide cada lunes", 10, color=AZUL)
fr += 1
encabezado(ws, fr, ["Anuncio", "", "Gasto ($)", "Leads", "", "", "Costo por lead", "", "Decisión", ""], color=AZUL)
fr += 1
decis = ["Seguir", "Apagar", "Probar variante B", "Aún sin datos"]
dvd = DataValidation(type="list", formula1='"' + ",".join(decis) + '"', allow_blank=True)
ws.add_data_validation(dvd)
a0 = fr
for i, a in enumerate(ANUNCIOS):
    celda(ws, fr, 1, f"{i + 1} · {a['nombre']}", negrita=True, fondo=FONDO)
    ws.merge_cells(start_row=fr, start_column=1, end_row=fr, end_column=2)
    entrada(ws, fr, 3, None, DINERO)
    entrada(ws, fr, 4, None)
    celda(ws, fr, 5, "")
    celda(ws, fr, 6, "")
    celda(ws, fr, 7, f'=IFERROR(IF(D{fr}=0,"",C{fr}/D{fr}),"")', formato=DINERO)
    celda(ws, fr, 8, "")
    entrada(ws, fr, 9, "Aún sin datos")
    dvd.add(f"I{fr}")
    celda(ws, fr, 10, "")
    ws.row_dimensions[fr].height = 20
    fr += 1
ws.conditional_formatting.add(f"G{a0}:G{fr - 1}", FormulaRule(formula=[f'AND(ISNUMBER(G{a0}),ISNUMBER({REF_CPL_LOCAL}),G{a0}>{REF_CPL_LOCAL})'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.conditional_formatting.add(f"I{a0}:I{fr - 1}", FormulaRule(formula=[f'I{a0}="Apagar"'], fill=cf(ROJO_FONDO), font=Font(bold=True, color=ROJO)))
ws.conditional_formatting.add(f"I{a0}:I{fr - 1}", FormulaRule(formula=[f'I{a0}="Seguir"'], fill=cf(VERDE_FONDO), font=Font(bold=True, color=VERDE)))
fr += 1
celda(ws, fr, 1, "Rojo = cuesta más de lo que aceptas (casilla «Costo máximo por lead» de «Empieza aquí»). Si ves rojo en todo durante 7 días, cambia la imagen o el texto A/B antes de subir presupuesto.",
      borde=False, color=GRIS, italica=True)
ws.merge_cells(start_row=fr, start_column=1, end_row=fr, end_column=10)
ws.row_dimensions[fr].height = 30
imprimir(ws)
ws.sheet_view.showGridLines = False

# ═════════════════════════════════════════════════════════════════════════
# 9 · Cómo subirlo
# ═════════════════════════════════════════════════════════════════════════
ws = wb.create_sheet(HOJA_SUBIR)
ws.sheet_properties.tabColor = DURAZNO
W = anchos(ws, [8, 38, 96])
titulo(ws, "Cómo subirlo a Meta · para Sammy o la diseñadora",
       "Unos 10 minutos por campaña. Yo no puedo entrar a tu cuenta de Meta ni publicar ni gastar: eso lo hace una persona de tu equipo. Los nombres de los botones están como aparecen en español.", 3)
ws.row_dimensions[2].height = 30
encabezado(ws, 4, ["Paso", "Dónde", "Qué hacer"])
pasos = [
    ("Administrador de anuncios", "Entra a business.facebook.com con la cuenta de Isabel → Administrador de anuncios → botón «Crear»."),
    ("Objetivo", "Elige «Clientes potenciales» (Leads)."),
    ("Campaña", "Nombre: «AEP 2026 · Clientes potenciales · español». En «Categorías de anuncios especiales» marca «Productos y servicios financieros» (país: Estados Unidos). Si Meta no ofrece la opción o dice que no aplica, detente y avísame: no sigas sin resolverlo."),
    ("Conjunto de anuncios", "Uno por región (crea el primero y duplícalo). «Ubicación de los clientes potenciales» → «Formularios instantáneos». Presupuesto diario y fechas: hoja «Campañas» (inicio mié 14 oct, fin lun 7 dic a las 11:59 pm)."),
    ("Audiencia", "«Ubicaciones» → agrega por radio (mínimo 15 millas) los puntos de la hoja «Campañas». «Idioma»: Español. La edad y el género no se pueden cambiar: así lo exige la categoría especial."),
    ("Ubicaciones de los anuncios", "«Ubicaciones Advantage+» (automáticas). Así los anuncios salen en Facebook e Instagram, en el Feed, las Historias y los Reels."),
    ("Anuncio · identidad", "Elige la página de Facebook de Isabel y su cuenta de Instagram."),
    ("Anuncio · imagen", "Formato «Imagen única». Sube la imagen de feed (4:5). En «Personalizar por ubicación» (Historias y Reels) sube la de 9:16. Los archivos van numerados igual que los anuncios de la hoja «Anuncios»."),
    ("Anuncio · textos", "Hoja «Anuncios»: copia el Texto principal A (desde ▼ hasta ▲) y pégalo en «Texto principal»; con «Agregar otra opción» pega el B. Titulares y descripción del mismo cuadro. Botón: «Más información»."),
    ("Anuncio · formulario", "«Formulario instantáneo» → «Crear formulario nuevo» y copia todo de la hoja «Formulario» (tipo «Mayor intención», preguntas, permiso, privacidad, pantalla de gracias). El mismo formulario sirve para todos los anuncios."),
    ("Revisar y publicar", "Mira la vista previa en Feed, Historias y Reels: el pie legal de la imagen debe leerse. Publica. Meta revisa en hasta ~1 día."),
    ("Después de aprobarse", "Toma una captura de pantalla de cada anuncio publicado (con la fecha) y guárdala en una carpeta «Anuncios AEP 2026»."),
    ("Avisos de leads", "Meta no manda un correo cuando llega un lead: quedan en el Centro de clientes potenciales (Leads Center) de Meta Business Suite. Para que a Isabel le llegue un correo al instante, conecta el formulario con su Gmail en Make.com (plan gratis, unos 10 minutos; los pasos están en la página de Sammy, «Segunda tarea»). Mientras tanto, revisen el Centro de clientes potenciales varias veces al día: un lead se llama en la primera hora."),
]
fila = 5
for k, (donde, que) in enumerate(pasos, 1):
    celda(ws, fila, 1, k, alinear=CENTRO, negrita=True, fondo=FONDO)
    celda(ws, fila, 2, donde, negrita=True)
    celda(ws, fila, 3, que)
    altura(ws, fila, W)
    fila += 1
fila += 1
banda(ws, fila, "Si Meta rechaza un anuncio", 3, color=ROJO)
fila += 1
rechazos = [
    ("Atributos personales", "Quita cualquier frase que hable de la edad, la salud o la situación económica de quien lo ve. Vuelve al texto original de la hoja «Anuncios» y pide revisión («Solicitar revisión»)."),
    ("Categoría especial", "Edita la campaña y declara «Productos y servicios financieros»."),
    ("Formulario o privacidad", "Revisa que el enlace de privacidad abra una página web (no un PDF) y que el formulario no pida datos sensibles."),
    ("Cualquier otro motivo", "Copia el motivo exacto que dice Meta y mándamelo: lo ajusto."),
]
for donde, que in rechazos:
    celda(ws, fila, 1, "", borde=True)
    celda(ws, fila, 2, donde, negrita=True)
    celda(ws, fila, 3, que)
    altura(ws, fila, W)
    fila += 1
fila += 1
banda(ws, fila, "Diccionario inglés ↔ español del Administrador de anuncios", 3, color=AZUL)
fila += 1
dic = [
    ("Leads", "Clientes potenciales"), ("Instant form", "Formulario instantáneo"), ("Special ad categories", "Categorías de anuncios especiales"),
    ("Financial products and services", "Productos y servicios financieros"), ("Ad set", "Conjunto de anuncios"), ("Placements", "Ubicaciones"),
    ("Primary text", "Texto principal"), ("Headline", "Titular (título)"), ("Description", "Descripción"), ("Call to action", "Botón de llamada a la acción"),
    ("Cost per lead", "Costo por cliente potencial"), ("Leads Center", "Centro de clientes potenciales"),
]
for ing, esp in dic:
    celda(ws, fila, 1, "", borde=True)
    celda(ws, fila, 2, ing, color=GRIS)
    celda(ws, fila, 3, esp, negrita=True)
    ws.row_dimensions[fila].height = 18
    fila += 1
fila += 1
banda(ws, fila, "Archivos de imagen (carpeta .zip)", 3, color=NAVY)
fila += 1
encabezado(ws, fila, ["#", "Anuncio", "Archivos"], color=AZUL)
fila += 1
for i, a in enumerate(ANUNCIOS):
    celda(ws, fila, 1, i + 1, alinear=CENTRO, fondo=FONDO)
    celda(ws, fila, 2, a["nombre"], negrita=True)
    celda(ws, fila, 3, f"{H_IMG(i, a, 'feed')}   ·   {H_IMG(i, a, 'historia')}")
    ws.row_dimensions[fila].height = 18
    fila += 1
imprimir(ws, horizontal=False)
ws.sheet_view.showGridLines = False

# ── Comprobación opcional contra el archivo de planes de Isabel ───────────
if PLANES:
    import openpyxl
    src = openpyxl.load_workbook(PLANES, data_only=True)
    todo = src["ALL CARRIERS "]
    filas = {str(todo.cell(r, 1).value).strip(): r for r in range(1, todo.max_row + 1) if todo.cell(r, 1).value}
    col = {}
    for c in range(2, todo.max_column + 1):
        col[str(todo.cell(filas["Plan"], c).value or "").strip()] = c

    def val(plan_inicio, etiqueta):
        for nombre, c in col.items():
            if nombre.startswith(plan_inicio):
                return str(todo.cell(filas[etiqueta], c).value)
        raise SystemExit(f"No encontré el plan «{plan_inicio}» en el archivo de planes")

    pruebas = [
        ("Humana Gold Plus Giveback (HMO) [LA, OC]", "Part B Giveback", "$84"), ("Humana Gold Plus Giveback (HMO) [LA, OC]", "Max Out-of-Pocket (MOOP)", "$2,700"),
        ("Humana Gold Plus Giveback (HMO) [LA, OC]", "Dental", "$1,000"), ("Humana Gold Plus Giveback (HMO) [LA, OC]", "Plan ID", "H5619-146-000"),
        ("SCAN Allied", "Part B Giveback", "$125"), ("SCAN Allied", "Max Out-of-Pocket (MOOP)", "$2,000"), ("SCAN Allied", "Flex Benefits (OTC + Grocery)", "$65/month"),
        ("SCAN Allied", "Plan ID", "H5425-123-000"), ("SCAN Essential Savings", "Part B Giveback", "$185"), ("SCAN Essential Savings", "Max Out-of-Pocket (MOOP)", "$2,400"),
        ("SCAN Essential Savings", "Plan ID", "H5425-133-000"), ("Anthem Select", "Max Out-of-Pocket (MOOP)", "$800"), ("Anthem Select", "Hearing", "$3,000"),
        ("Anthem Select", "Dental", "$500"), ("Anthem Select", "OTC Allowance", "$32"), ("Anthem Select", "Plan ID", "H0544-058-000"),
        ("Humana Gold Plus (HMO) [LA, OC]", "Max Out-of-Pocket (MOOP)", "$999"), ("Humana Gold Plus (HMO) [LA, OC]", "Dental", "$3,000"),
        ("Humana Gold Plus (HMO) [LA, OC]", "OTC Allowance", "$50"), ("Humana Gold Plus (HMO) [LA, OC]", "Plan ID", "H3767-002-000"),
        ("L.A. Care Medicare Plus", "Flex Benefits (OTC + Grocery)", "$120/month"), ("L.A. Care Medicare Plus", "Plan ID", "H1224-001-000"),
        ("SCAN Connections", "Flex Benefits (OTC + Grocery)", "$110/month"), ("SCAN Connections", "Dental", "$5,000"), ("SCAN Connections", "Plan ID", "H0976-001-000"),
    ]
    for plan_, et, esperado in pruebas:
        real = val(plan_, et)
        assert esperado in real, f"{plan_} · {et}: esperaba «{esperado}» y el archivo dice «{real[:80]}»"
    costco = src["SCAN Costco"]
    etiquetas = {str(costco.cell(r, 1).value).strip(): r for r in range(1, costco.max_row + 1) if costco.cell(r, 1).value}
    for c in range(2, costco.max_column + 1):
        nombre = str(costco.cell(etiquetas["Plan"], c).value)
        if "[LA]" in nombre or "[OC]" in nombre:
            assert "$299" in str(costco.cell(etiquetas["Max Out-of-Pocket (MOOP)"], c).value), nombre
            assert "$2,000" in str(costco.cell(etiquetas["Dental"], c).value), nombre
            assert "$115" in str(costco.cell(etiquetas["Flex Benefits (OTC + Grocery)"], c).value), nombre
            assert str(costco.cell(etiquetas["Plan ID"], c).value).startswith("H5425-14"), nombre
    print(f"Cifras de «{HOJA_PLAN}» verificadas contra {os.path.basename(PLANES)} ({len(pruebas)} datos + Costco LA/OC)")

wb.calculation.fullCalcOnLoad = True
os.makedirs(os.path.dirname(os.path.abspath(OUT)), exist_ok=True)
wb.save(OUT)
print("Guardado:", OUT)
