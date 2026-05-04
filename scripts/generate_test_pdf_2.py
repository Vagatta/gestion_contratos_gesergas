"""Genera un segundo PDF de prueba con datos distintos para OCR."""
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import cm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle
from reportlab.lib import colors
from pathlib import Path

out = Path(__file__).resolve().parent.parent / "uploads" / "contracts" / "contrato_prueba_2.pdf"
out.parent.mkdir(parents=True, exist_ok=True)

doc = SimpleDocTemplate(str(out), pagesize=A4,
                        leftMargin=2*cm, rightMargin=2*cm,
                        topMargin=2*cm, bottomMargin=2*cm)

styles = getSampleStyleSheet()
title = ParagraphStyle('title', parent=styles['Heading1'], fontSize=18,
                      spaceAfter=12, alignment=1, textColor=colors.HexColor('#3525cd'))
h2 = ParagraphStyle('h2', parent=styles['Heading2'], fontSize=13, spaceAfter=8,
                    textColor=colors.HexColor('#0b1c30'))
body = ParagraphStyle('body', parent=styles['BodyText'], fontSize=11, leading=16)

story = []

story.append(Paragraph("CONTRATO DE PRESTACIÓN DE SERVICIOS", title))
story.append(Spacer(1, 0.3*cm))
story.append(Paragraph("Referencia: SRV-2026-0482", body))
story.append(Paragraph("Fecha: 15 de junio de 2026", body))
story.append(Spacer(1, 0.4*cm))

story.append(Paragraph("PARTES INTERVINIENTES", h2))
story.append(Paragraph(
    "De una parte, la empresa <b>Tecnología Inteligente S.L.</b>, con CIF B-87654321, "
    "con domicilio en Avenida de la Innovación 42, 08028 Barcelona, en adelante el PRESTADOR.",
    body))
story.append(Spacer(1, 0.3*cm))
story.append(Paragraph(
    "De otra parte, <b>Doña Laura Fernández Ruiz</b>, con DNI 48293756-K, "
    "mayor de edad, con domicilio en Calle Mayor 18, 3º B, 28013 Madrid, "
    "en adelante el CLIENTE.",
    body))

story.append(Spacer(1, 0.4*cm))
story.append(Paragraph("DATOS DE CONTACTO DEL CLIENTE", h2))

data = [
    ["Nombre completo:", "Laura Fernández Ruiz"],
    ["Dirección:",       "Calle Mayor 18, 3º B, 28013 Madrid"],
    ["Teléfono móvil:",  "+34 654 782 109"],
    ["Email:",           "laura.fernandez@correoejemplo.com"],
    ["WhatsApp:",        "+34 654 782 109"],
    ["Fecha contrato:",  "2026-06-15"],
]

table = Table(data, colWidths=[4.5*cm, 10*cm])
table.setStyle(TableStyle([
    ('FONTNAME',    (0,0), (-1,-1), 'Helvetica'),
    ('FONTSIZE',    (0,0), (-1,-1), 10),
    ('TEXTCOLOR',   (0,0), (0,-1),  colors.HexColor('#464555')),
    ('FONTNAME',    (0,0), (0,-1),  'Helvetica-Bold'),
    ('BACKGROUND',  (0,0), (-1,-1), colors.HexColor('#f8f9ff')),
    ('BOX',         (0,0), (-1,-1), 0.5, colors.HexColor('#c7c4d8')),
    ('INNERGRID',   (0,0), (-1,-1), 0.25, colors.HexColor('#e5eeff')),
    ('VALIGN',      (0,0), (-1,-1), 'MIDDLE'),
    ('LEFTPADDING', (0,0), (-1,-1), 10),
    ('RIGHTPADDING',(0,0), (-1,-1), 10),
    ('TOPPADDING',  (0,0), (-1,-1), 6),
    ('BOTTOMPADDING',(0,0),(-1,-1), 6),
]))
story.append(table)

story.append(Spacer(1, 0.5*cm))
story.append(Paragraph("OBJETO DEL CONTRATO", h2))
story.append(Paragraph(
    "El PRESTADOR se compromete a ofrecer al CLIENTE servicios de consultoría "
    "tecnológica y mantenimiento informático durante un período de doce (12) meses, "
    "con renovación automática salvo preaviso de una parte con al menos treinta (30) días de antelación.",
    body))

story.append(Spacer(1, 0.4*cm))
story.append(Paragraph("CONDICIONES ECONÓMICAS", h2))
story.append(Paragraph(
    "El precio total del servicio asciende a 2.400,00 € (DOS MIL CUATROCIENTOS EUROS) anuales, "
    "pagaderos en 12 cuotas mensuales de 200,00 € cada una mediante domiciliación bancaria.",
    body))

story.append(Spacer(1, 0.4*cm))
story.append(Paragraph("VIGENCIA Y RENOVACIÓN", h2))
story.append(Paragraph(
    "El contrato entra en vigor el 15 de junio de 2026 y tendrá una vigencia inicial de "
    "doce meses, finalizando el 15 de junio de 2027. Se renovará tácitamente salvo denuncia "
    "expresa por cualquiera de las partes.",
    body))

story.append(Spacer(1, 0.6*cm))
story.append(Paragraph("Firmado en Madrid, a 15 de junio de 2026.", body))

doc.build(story)
print(f"PDF generado: {out}")
print(f"Tamaño: {out.stat().st_size} bytes")
