package bo.edu.uagrm.firmador.pdf;

import java.awt.Color;
import java.awt.color.ColorSpace;
import java.awt.color.ICC_Profile;
import java.awt.image.BufferedImage;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Map;

import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDDocumentCatalog;
import org.apache.pdfbox.pdmodel.PDDocumentInformation;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.common.PDMetadata;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.apache.pdfbox.pdmodel.graphics.color.PDOutputIntent;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.xmpbox.XMPMetadata;
import org.apache.xmpbox.schema.AdobePDFSchema;
import org.apache.xmpbox.schema.DublinCoreSchema;
import org.apache.xmpbox.schema.PDFAIdentificationSchema;
import org.apache.xmpbox.schema.XMPBasicSchema;
import org.apache.xmpbox.xml.XmpSerializer;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;

import com.google.zxing.BarcodeFormat;
import com.google.zxing.EncodeHintType;
import com.google.zxing.WriterException;
import com.google.zxing.common.BitMatrix;
import com.google.zxing.qrcode.QRCodeWriter;
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel;

import bo.edu.uagrm.firmador.web.FirmadorException;

/**
 * CU6 / RF-14: certificado oficial de legalización (CL-) o constancia de descuento (CD-) en PDF/A-2b
 * (fuentes incrustadas, perfil sRGB, metadatos XMP) con el código QR de validación.
 */
@Service
public class CertificadoOficialService {

    private static final String PRODUCTOR = "Sistema de Legalizaciones con Firma Digital - UAGRM (INF513)";
    private static final Color AZUL = new Color(23, 37, 84);
    private static final Color GRIS = new Color(71, 85, 105);
    private static final Color GRIS_CLARO = new Color(203, 213, 225);
    private static final float MARGEN = 56f;

    public byte[] generar(DatosCertificadoOficial d) {
        try (PDDocument doc = new PDDocument()) {
            PDType0Font normal = cargarFuente(doc, "/fuentes/DejaVuSans.ttf");
            PDType0Font negrita = cargarFuente(doc, "/fuentes/DejaVuSans-Bold.ttf");
            String titulo = d.esConstancia() ? "CONSTANCIA DE DESCUENTO DEL 50% EN DIPLOMADO" : "CERTIFICADO DE LEGALIZACIÓN";

            PDPage pagina = new PDPage(PDRectangle.A4);
            doc.addPage(pagina);
            float ancho = pagina.getMediaBox().getWidth();
            float anchoTexto = ancho - 2 * MARGEN;

            try (PDPageContentStream cs = new PDPageContentStream(doc, pagina)) {
                // Encabezado institucional
                cs.setNonStrokingColor(AZUL);
                cs.addRect(0, 752, ancho, 90);
                cs.fill();
                texto(cs, negrita, 14, MARGEN, 808, "UNIVERSIDAD AUTÓNOMA GABRIEL RENÉ MORENO", Color.WHITE);
                texto(cs, normal, 11, MARGEN, 789, "VICERRECTORADO · Unidad de Legalizaciones", Color.WHITE);
                texto(cs, normal, 9, MARGEN, 772, "Santa Cruz de la Sierra - Bolivia", new Color(191, 219, 254));

                float y = 712;
                y = centrado(cs, negrita, 16, ancho, y, titulo, AZUL) - 20;
                y = centrado(cs, negrita, 12, ancho, y, "N° " + d.numero(), Color.BLACK) - 6;
                y = centrado(cs, normal, 9, ancho, y, "Trámite " + d.codigoTramite() + " · Emitido el " + d.fechaEmision(), GRIS) - 26;

                y = parrafo(cs, normal, 10.5f, MARGEN, y, anchoTexto, cuerpo(d), Color.BLACK, 15f) - 10;

                if (!d.esConstancia() && d.documentos() != null && !d.documentos().isEmpty()) {
                    texto(cs, negrita, 10, MARGEN, y, "Documentos legalizados", AZUL);
                    texto(cs, negrita, 10, ancho - MARGEN - 80, y, "Fojas", AZUL);
                    y -= 6;
                    linea(cs, MARGEN, y, ancho - MARGEN);
                    y -= 14;
                    int n = 0;
                    for (DatosCertificadoOficial.Documento doc1 : d.documentos()) {
                        if (y < 330) {
                            texto(cs, normal, 9, MARGEN, y, "… y " + (d.documentos().size() - n) + " documento(s) más (ver expediente).", GRIS);
                            y -= 14;
                            break;
                        }
                        n++;
                        texto(cs, normal, 10, MARGEN, y, recortar(n + ". " + doc1.nombre(), normal, 10, anchoTexto - 100), Color.BLACK);
                        String folios = doc1.folioInicio() == null ? "—" : doc1.folioInicio() + " – " + doc1.folioFin();
                        texto(cs, normal, 10, ancho - MARGEN - 80, y, folios, Color.BLACK);
                        y -= 15;
                    }
                    texto(cs, normal, 9, MARGEN, y, "Total de fojas del expediente: " + (d.totalFojas() == null ? "—" : d.totalFojas()), GRIS);
                    y -= 22;
                }

                // Leyenda legal de la firma (fn_leyenda_firma)
                texto(cs, negrita, 10, MARGEN, y, "Firma digital de la autoridad", AZUL);
                y -= 6;
                linea(cs, MARGEN, y, ancho - MARGEN);
                y -= 15;
                y = parrafo(cs, normal, 10, MARGEN, y, anchoTexto, nvl(d.leyendaFirma()), Color.BLACK, 14f);
                if (d.fechaFirma() != null) {
                    y = parrafo(cs, normal, 9, MARGEN, y - 2, anchoTexto, "Fecha de la firma digital del expediente: " + d.fechaFirma(), GRIS, 13f);
                }

                // Bloque de verificación con QR
                float alto = 150f;
                float base = 92f;
                cs.setStrokingColor(GRIS_CLARO);
                cs.setLineWidth(0.8f);
                cs.addRect(MARGEN, base, anchoTexto, alto);
                cs.stroke();
                PDImageXObject qr = qr(doc, d.qrContenido());
                cs.drawImage(qr, MARGEN + 10, base + 10, alto - 20, alto - 20);
                float xt = MARGEN + alto + 2;
                float anchoBloque = anchoTexto - alto - 10;
                float yt = base + alto - 20;
                texto(cs, negrita, 10, xt, yt, "Verificación de autenticidad", AZUL);
                yt = parrafo(cs, normal, 8.5f, xt, yt - 15, anchoBloque,
                        "Escanee el código QR o ingrese el código de verificación en el validador público del sistema.", GRIS, 11f);
                texto(cs, normal, 8, xt, yt - 4, "Código de verificación:", GRIS);
                texto(cs, negrita, 8.5f, xt, yt - 16, nvl(d.codigoVerificacion()), Color.BLACK);
                texto(cs, normal, 8, xt, yt - 32, "SHA-256 del expediente firmado:", GRIS);
                String hash = nvl(d.hashExpediente());
                texto(cs, normal, 7.5f, xt, yt - 43, hash.substring(0, Math.min(32, hash.length())), Color.BLACK);
                if (hash.length() > 32) {
                    texto(cs, normal, 7.5f, xt, yt - 53, hash.substring(32), Color.BLACK);
                }

                parrafo(cs, normal, 7.5f, MARGEN, 70, anchoTexto,
                        "Documento PDF/A firmado digitalmente con el sello electrónico del Vicerrectorado UAGRM. "
                                + "Cualquier alteración del archivo invalida la firma. Proyecto académico INF513: "
                                + "la Autoridad Certificadora de prueba reemplaza a ADSIB y el documento no tiene validez legal.",
                        GRIS, 10f);
            }

            configurarPdfA(doc, titulo + " " + d.numero(), d.titular());
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            doc.save(out);
            return out.toByteArray();
        } catch (IOException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "No se pudo generar el certificado oficial: " + e.getMessage(), e);
        }
    }

    private String cuerpo(DatosCertificadoOficial d) {
        String identificacion = nvl(d.titular()) + ", con C.I. " + nvl(d.ci()) + " y Registro Universitario " + nvl(d.registro())
                + ", de la carrera de " + nvl(d.carrera());
        if (d.esConstancia()) {
            DatosCertificadoOficial.Descuento desc = d.descuento();
            String diplomado = desc == null ? "—" : nvl(desc.nombreDiplomado()) + " (" + nvl(desc.unidadPostgrado())
                    + ", gestión " + nvl(desc.gestion()) + ")";
            String situacion = desc == null || desc.financiamiento() == null ? "—" : desc.financiamiento().replace('_', ' ');
            return "El Vicerrectorado de la Universidad Autónoma Gabriel René Moreno HACE CONSTAR que " + identificacion
                    + ", es beneficiario/a del descuento del 50% en el diplomado " + diplomado
                    + ", conforme a la Resolución Vicerrectoral 01-28/2013, con situación " + situacion
                    + ", según el trámite " + d.codigoTramite() + ".";
        }
        boolean sinInstitucion = d.institucionDestino() == null || d.institucionDestino().isBlank();
        String destino = "EXTERIOR".equals(d.destino())
                ? "con destino al exterior (" + nvl(d.paisDestino()) + ")" + (sinInstitucion ? "" : ", ante " + d.institucionDestino())
                        + ", con visado institucional de Rectorado previo a la Apostilla"
                : "para uso nacional" + (sinInstitucion ? "" : ", ante " + d.institucionDestino());
        return "El Vicerrectorado de la Universidad Autónoma Gabriel René Moreno CERTIFICA que los documentos académicos "
                + "detallados a continuación, pertenecientes a " + identificacion + ", fueron revisados, foliados y "
                + "legalizados digitalmente en el trámite " + d.codigoTramite() + ", " + destino + ".";
    }

    /** PDF/A-2b: metadatos XMP con identificación PDF/A, perfil de color sRGB e idioma del documento. */
    private void configurarPdfA(PDDocument doc, String titulo, String titular) throws IOException {
        Calendar ahora = Calendar.getInstance();
        PDDocumentInformation info = doc.getDocumentInformation();
        info.setTitle(titulo);
        info.setAuthor("Vicerrectorado UAGRM");
        info.setSubject("Documento oficial con código QR de validación" + (titular == null ? "" : " - " + titular));
        info.setCreator(PRODUCTOR);
        info.setProducer(PRODUCTOR);
        info.setCreationDate(ahora);
        info.setModificationDate(ahora);

        try {
            XMPMetadata xmp = XMPMetadata.createXMPMetadata();
            DublinCoreSchema dc = xmp.createAndAddDublinCoreSchema();
            dc.setTitle(titulo);
            dc.addCreator("Vicerrectorado UAGRM");
            dc.setDescription(info.getSubject());
            PDFAIdentificationSchema pdfa = xmp.createAndAddPDFAIdentificationSchema();
            pdfa.setPart(2);
            pdfa.setConformance("B");
            AdobePDFSchema adobe = xmp.createAndAddAdobePDFSchema();
            adobe.setProducer(PRODUCTOR);
            XMPBasicSchema basico = xmp.createAndAddXMPBasicSchema();
            basico.setCreatorTool(PRODUCTOR);
            basico.setCreateDate(ahora);
            basico.setModifyDate(ahora);

            ByteArrayOutputStream xml = new ByteArrayOutputStream();
            new XmpSerializer().serialize(xmp, xml, true);
            PDMetadata metadatos = new PDMetadata(doc);
            metadatos.importXMPMetadata(xml.toByteArray());
            PDDocumentCatalog catalogo = doc.getDocumentCatalog();
            catalogo.setMetadata(metadatos);
            catalogo.setLanguage("es-BO");

            byte[] icc = ICC_Profile.getInstance(ColorSpace.CS_sRGB).getData();
            PDOutputIntent intencion = new PDOutputIntent(doc, new ByteArrayInputStream(icc));
            intencion.setInfo("sRGB IEC61966-2.1");
            intencion.setOutputCondition("sRGB IEC61966-2.1");
            intencion.setOutputConditionIdentifier("sRGB IEC61966-2.1");
            intencion.setRegistryName("http://www.color.org");
            catalogo.addOutputIntent(intencion);
        } catch (Exception e) {
            throw new IOException("No se pudieron registrar los metadatos PDF/A: " + e.getMessage(), e);
        }
    }

    private PDImageXObject qr(PDDocument doc, String contenido) throws IOException {
        if (contenido == null || contenido.isBlank()) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El contenido del QR es obligatorio.");
        }
        try {
            BitMatrix matriz = new QRCodeWriter().encode(contenido, BarcodeFormat.QR_CODE, 360, 360,
                    Map.of(EncodeHintType.ERROR_CORRECTION, ErrorCorrectionLevel.M, EncodeHintType.MARGIN, 1,
                            EncodeHintType.CHARACTER_SET, "UTF-8"));
            BufferedImage imagen = new BufferedImage(matriz.getWidth(), matriz.getHeight(), BufferedImage.TYPE_BYTE_GRAY);
            for (int x = 0; x < matriz.getWidth(); x++) {
                for (int y = 0; y < matriz.getHeight(); y++) {
                    imagen.setRGB(x, y, matriz.get(x, y) ? 0x000000 : 0xFFFFFF);
                }
            }
            return LosslessFactory.createFromImage(doc, imagen);
        } catch (WriterException e) {
            throw new IOException("No se pudo generar el código QR: " + e.getMessage(), e);
        }
    }

    private PDType0Font cargarFuente(PDDocument doc, String recurso) throws IOException {
        try (InputStream in = getClass().getResourceAsStream(recurso)) {
            if (in == null) {
                throw new IOException("No se encontró la fuente " + recurso);
            }
            return PDType0Font.load(doc, in, true);
        }
    }

    private void texto(PDPageContentStream cs, PDType0Font fuente, float tamano, float x, float y, String t, Color color)
            throws IOException {
        cs.beginText();
        cs.setNonStrokingColor(color);
        cs.setFont(fuente, tamano);
        cs.newLineAtOffset(x, y);
        cs.showText(t == null ? "" : t.replaceAll("[\\r\\n\\t]", " "));
        cs.endText();
    }

    private float centrado(PDPageContentStream cs, PDType0Font fuente, float tamano, float ancho, float y, String t, Color color)
            throws IOException {
        float w = fuente.getStringWidth(t) / 1000 * tamano;
        texto(cs, fuente, tamano, (ancho - w) / 2, y, t, color);
        return y - tamano * 1.2f;
    }

    /** Escribe un párrafo con ajuste de línea y devuelve la coordenada y siguiente. */
    private float parrafo(PDPageContentStream cs, PDType0Font fuente, float tamano, float x, float y, float ancho, String t,
                          Color color, float interlineado) throws IOException {
        for (String linea : ajustar(t, fuente, tamano, ancho)) {
            texto(cs, fuente, tamano, x, y, linea, color);
            y -= interlineado;
        }
        return y;
    }

    private List<String> ajustar(String t, PDType0Font fuente, float tamano, float ancho) throws IOException {
        List<String> lineas = new ArrayList<>();
        StringBuilder actual = new StringBuilder();
        for (String palabra : nvl(t).split("\\s+")) {
            String prueba = actual.isEmpty() ? palabra : actual + " " + palabra;
            if (fuente.getStringWidth(prueba) / 1000 * tamano > ancho && !actual.isEmpty()) {
                lineas.add(actual.toString());
                actual = new StringBuilder(palabra);
            } else {
                actual = new StringBuilder(prueba);
            }
        }
        if (!actual.isEmpty()) lineas.add(actual.toString());
        return lineas;
    }

    private String recortar(String t, PDType0Font fuente, float tamano, float ancho) throws IOException {
        String r = t;
        while (r.length() > 4 && fuente.getStringWidth(r) / 1000 * tamano > ancho) {
            r = r.substring(0, r.length() - 2);
        }
        return r.equals(t) ? r : r + "…";
    }

    private void linea(PDPageContentStream cs, float x1, float y, float x2) throws IOException {
        cs.setStrokingColor(GRIS_CLARO);
        cs.setLineWidth(0.8f);
        cs.moveTo(x1, y);
        cs.lineTo(x2, y);
        cs.stroke();
    }

    private static String nvl(String s) {
        return s == null || s.isBlank() ? "—" : s;
    }
}
