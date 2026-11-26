package bo.edu.uagrm.firmador.pdf;

import java.awt.Color;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.Locale;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.multipdf.PDFMergerUtility;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDDocumentInformation;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;

import bo.edu.uagrm.firmador.web.FirmadorException;

/**
 * Compila el legajo único del trámite: carátula + documentos (PDF o imagen) en orden, con foliación continua
 * estampada en cada foja. Es el PDF que la autoridad previsualiza y firma.
 */
@Service
public class CompiladorLegajoService {

    private static final DateTimeFormatter FORMATO = DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm");
    private final PDType1Font normal = new PDType1Font(Standard14Fonts.FontName.HELVETICA);
    private final PDType1Font negrita = new PDType1Font(Standard14Fonts.FontName.HELVETICA_BOLD);

    public record LegajoCompilado(byte[] pdf, int totalFojas, String hashSha256) {
    }

    public LegajoCompilado compilar(MetadatosLegajo meta, List<MultipartFile> archivos) {
        try (PDDocument legajo = new PDDocument()) {
            PDDocumentInformation info = legajo.getDocumentInformation();
            info.setTitle("Legajo " + meta.codigo());
            info.setAuthor("Vicerrectorado UAGRM");
            info.setSubject("Expediente compilado y foliado");
            info.setCreator("Firmador INF513");

            legajo.addPage(new PDPage(PDRectangle.A4)); // carátula (se dibuja al final, ya con el total de fojas)

            PDFMergerUtility fusion = new PDFMergerUtility();
            for (MultipartFile archivo : archivos == null ? List.<MultipartFile>of() : archivos) {
                incorporar(legajo, fusion, archivo);
            }
            if (legajo.getNumberOfPages() == 1) {
                paginaAviso(legajo, "El trámite no tiene archivos digitales adjuntos.",
                        "Se compila únicamente la carátula con los datos registrados.");
            }

            int totalFojas = legajo.getNumberOfPages() - 1;
            dibujarCaratula(legajo, legajo.getPage(0), meta, totalFojas);
            for (int i = 1; i < legajo.getNumberOfPages(); i++) {
                estamparFolio(legajo, legajo.getPage(i), meta.codigo(), i, totalFojas);
            }

            ByteArrayOutputStream out = new ByteArrayOutputStream();
            legajo.save(out);
            byte[] bytes = out.toByteArray();
            return new LegajoCompilado(bytes, totalFojas, FirmaPadesService.sha256(bytes));
        } catch (IOException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "No se pudo compilar el legajo: " + e.getMessage(), e);
        }
    }

    private void incorporar(PDDocument legajo, PDFMergerUtility fusion, MultipartFile archivo) throws IOException {
        String nombre = archivo.getOriginalFilename() == null ? "archivo" : archivo.getOriginalFilename();
        String minus = nombre.toLowerCase(Locale.ROOT);
        byte[] bytes = archivo.getBytes();
        try {
            if (minus.endsWith(".pdf") || "application/pdf".equals(archivo.getContentType())) {
                try (PDDocument origen = Loader.loadPDF(bytes)) {
                    if (origen.isEncrypted()) {
                        origen.setAllSecurityToBeRemoved(true);
                    }
                    PDAcroForm acroForm = origen.getDocumentCatalog().getAcroForm(null);
                    if (acroForm != null) {
                        acroForm.flatten();
                    }
                    fusion.appendDocument(legajo, origen);
                }
            } else if (minus.matches(".*\\.(png|jpe?g)$")) {
                PDImageXObject imagen = PDImageXObject.createFromByteArray(legajo, bytes, nombre);
                PDPage pagina = new PDPage(PDRectangle.A4);
                legajo.addPage(pagina);
                float margen = 36f;
                float anchoMax = PDRectangle.A4.getWidth() - 2 * margen;
                float altoMax = PDRectangle.A4.getHeight() - 2 * margen - 20f;
                float escala = Math.min(anchoMax / imagen.getWidth(), altoMax / imagen.getHeight());
                float ancho = imagen.getWidth() * escala;
                float alto = imagen.getHeight() * escala;
                try (PDPageContentStream cs = new PDPageContentStream(legajo, pagina)) {
                    cs.drawImage(imagen, (PDRectangle.A4.getWidth() - ancho) / 2, margen + (altoMax - alto) / 2, ancho, alto);
                }
            } else {
                paginaAviso(legajo, "Archivo no admitido en el legajo: " + nombre, "Solo se compilan archivos PDF, JPG o PNG.");
            }
        } catch (IOException e) {
            paginaAviso(legajo, "No se pudo incorporar el archivo: " + nombre, "Motivo: " + e.getMessage());
        }
    }

    private void paginaAviso(PDDocument legajo, String titulo, String detalle) throws IOException {
        PDPage pagina = new PDPage(PDRectangle.A4);
        legajo.addPage(pagina);
        try (PDPageContentStream cs = new PDPageContentStream(legajo, pagina)) {
            texto(cs, negrita, 12, 60, 720, titulo, new Color(153, 27, 27));
            texto(cs, normal, 10, 60, 700, detalle, new Color(71, 85, 105));
        }
    }

    private void dibujarCaratula(PDDocument legajo, PDPage pagina, MetadatosLegajo meta, int totalFojas) throws IOException {
        float ancho = pagina.getMediaBox().getWidth();
        Color azul = new Color(23, 37, 84);
        Color gris = new Color(71, 85, 105);
        try (PDPageContentStream cs = new PDPageContentStream(legajo, pagina)) {
            cs.setNonStrokingColor(azul);
            cs.addRect(0, 760, ancho, 82);
            cs.fill();
            texto(cs, negrita, 15, 50, 805, "UNIVERSIDAD AUTÓNOMA GABRIEL RENÉ MORENO", Color.WHITE);
            texto(cs, normal, 11, 50, 785, "Vicerrectorado · Unidad de Legalizaciones", Color.WHITE);
            texto(cs, negrita, 20, 50, 715, "LEGAJO DEL TRÁMITE " + nvl(meta.codigo()), azul);
            texto(cs, normal, 10, 50, 698, "Expediente compilado con foliación continua · versión " + (meta.version() == null ? 1 : meta.version()), gris);

            float y = 660;
            y = fila(cs, y, "Tipo de trámite", nvl(meta.tipo()).replace('_', ' '));
            String destino = nvl(meta.destino()) + (meta.paisDestino() != null && !meta.paisDestino().isBlank() ? " · " + meta.paisDestino() : "");
            y = fila(cs, y, "Destino", destino);
            y = fila(cs, y, "Solicitante", nvl(meta.estudiante()));
            y = fila(cs, y, "C.I. / Registro", nvl(meta.ci()) + " / " + nvl(meta.registro()));
            y = fila(cs, y, "Carrera", nvl(meta.carrera()));
            y = fila(cs, y, "Total de fojas", String.valueOf(totalFojas));
            y = fila(cs, y, "Compilado", FORMATO.format(ZonedDateTime.now(ZoneId.of("America/La_Paz"))));

            y -= 18;
            texto(cs, negrita, 12, 50, y, "Índice de documentos", azul);
            y -= 8;
            cs.setStrokingColor(new Color(203, 213, 225));
            cs.moveTo(50, y);
            cs.lineTo(ancho - 50, y);
            cs.stroke();
            y -= 16;
            List<MetadatosLegajo.Documento> docs = meta.documentos() == null ? List.of() : meta.documentos();
            if (docs.isEmpty()) {
                texto(cs, normal, 10, 50, y, "Sin documentos de legalización (trámite de descuento o expediente único).", gris);
            }
            int n = 0;
            for (MetadatosLegajo.Documento d : docs) {
                if (y < 90) {
                    texto(cs, normal, 9, 50, y, "… y " + (docs.size() - n) + " documento(s) más.", gris);
                    break;
                }
                n++;
                String folios = d.folioInicio() == null ? "—" : "Fojas " + d.folioInicio() + "–" + d.folioFin();
                texto(cs, negrita, 10, 50, y, n + ". " + recortar(nvl(d.nombre()), negrita, 10, 330), Color.BLACK);
                texto(cs, normal, 9, 400, y, folios, gris);
                y -= 12;
                String extra = (d.nroValorado() != null ? "Valorado N° " + d.nroValorado() : "Sin valorado")
                        + (d.archivo() == null ? " · sin archivo digital" : "");
                texto(cs, normal, 8.5f, 64, y, extra, gris);
                y -= 16;
            }
            texto(cs, normal, 8, 50, 40,
                    "Documento generado por el Sistema de Legalizaciones con Firma Digital · Proyecto académico INF513", gris);
        }
    }

    private float fila(PDPageContentStream cs, float y, String etiqueta, String valor) throws IOException {
        texto(cs, normal, 10, 50, y, etiqueta, new Color(100, 116, 139));
        texto(cs, negrita, 10, 170, y, recortar(valor, negrita, 10, 370), Color.BLACK);
        return y - 18;
    }

    private void estamparFolio(PDDocument doc, PDPage pagina, String codigo, int foja, int total) throws IOException {
        PDRectangle caja = pagina.getCropBox();
        String etiqueta = "UAGRM · " + nvl(codigo) + " · Foja " + foja + " de " + total;
        float tamano = 8f;
        float ancho = negrita.getStringWidth(TextoPdf.seguro(etiqueta, negrita)) / 1000 * tamano;
        float x = caja.getLowerLeftX() + caja.getWidth() - ancho - 18;
        float y = caja.getLowerLeftY() + caja.getHeight() - 16;
        try (PDPageContentStream cs = new PDPageContentStream(doc, pagina, PDPageContentStream.AppendMode.APPEND, true, true)) {
            cs.setNonStrokingColor(Color.WHITE);
            cs.addRect(x - 4, y - 3, ancho + 8, tamano + 6);
            cs.fill();
            cs.setStrokingColor(new Color(23, 37, 84));
            cs.setLineWidth(0.6f);
            cs.addRect(x - 4, y - 3, ancho + 8, tamano + 6);
            cs.stroke();
            texto(cs, negrita, tamano, x, y, etiqueta, new Color(23, 37, 84));
        }
    }

    private void texto(PDPageContentStream cs, PDType1Font fuente, float tamano, float x, float y, String texto, Color color)
            throws IOException {
        cs.beginText();
        cs.setNonStrokingColor(color);
        cs.setFont(fuente, tamano);
        cs.newLineAtOffset(x, y);
        cs.showText(TextoPdf.seguro(texto, fuente));
        cs.endText();
    }

    private String recortar(String texto, PDType1Font fuente, float tamano, float ancho) throws IOException {
        String t = TextoPdf.seguro(texto, fuente);
        String original = t;
        while (t.length() > 4 && fuente.getStringWidth(t) / 1000 * tamano > ancho) {
            t = t.substring(0, t.length() - 2);
        }
        return t.equals(original) ? t : t + "…";
    }

    private static String nvl(String s) {
        return s == null || s.isBlank() ? "—" : s;
    }
}
