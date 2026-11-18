package bo.edu.uagrm.firmador.pdf;

import java.awt.Color;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Calendar;
import java.util.GregorianCalendar;
import java.util.HexFormat;
import java.util.List;
import java.util.TimeZone;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDResources;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.common.PDStream;
import org.apache.pdfbox.pdmodel.encryption.InvalidPasswordException;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.graphics.form.PDFormXObject;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceDictionary;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceStream;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;

import bo.edu.uagrm.firmador.ac.CredencialFirma;
import bo.edu.uagrm.firmador.web.FirmadorException;

/**
 * Firma PAdES (ETSI.CAdES.detached) con guardado incremental: los bytes originales del legajo no se modifican
 * y una firma posterior (sello de Rectorado) se agrega como nueva revisión sin invalidar la anterior.
 */
@Service
public class FirmaPadesService {

    private static final ZoneId ZONA = ZoneId.of("America/La_Paz");
    private static final DateTimeFormatter FORMATO = DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm:ss");
    private static final float ANCHO_SELLO = 250f;
    private static final float ALTO_SELLO = 64f;
    private static final float MARGEN = 28f;

    public ResultadoFirma firmar(byte[] pdf, CredencialFirma credencial, DatosFirma datos) {
        try (PDDocument documento = Loader.loadPDF(pdf)) {
            if (documento.isEncrypted()) {
                throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El legajo está cifrado y no puede firmarse.");
            }
            ZonedDateTime ahora = ZonedDateTime.now(ZONA);
            Calendar fecha = GregorianCalendar.from(ahora);
            fecha.setTimeZone(TimeZone.getTimeZone(ZONA));

            PDSignature firma = new PDSignature();
            firma.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
            firma.setSubFilter(PDSignature.SUBFILTER_ETSI_CADES_DETACHED);
            firma.setName(datos.nombre());
            firma.setReason(datos.razon());
            firma.setLocation(datos.ubicacion());
            firma.setContactInfo(datos.contacto());
            firma.setSignDate(fecha); // /M: la hora de la firma en PAdES

            int existentes = documento.getSignatureDictionaries().size();
            int indicePagina = documento.getNumberOfPages() - 1;
            PDRectangle caja = cajaSello(documento.getPage(indicePagina), existentes);

            FirmadorCms firmadorCms = new FirmadorCms(credencial.clave(), credencial.cadena());
            ByteArrayOutputStream salida = new ByteArrayOutputStream(pdf.length + 64_000);
            try (SignatureOptions opciones = new SignatureOptions()) {
                opciones.setPreferredSignatureSize(SignatureOptions.DEFAULT_SIGNATURE_SIZE * 2);
                // Firma invisible para PDF/A: el certificado ya imprime la leyenda con fuentes incrustadas
                if (datos.visible()) {
                    opciones.setVisualSignature(plantillaVisible(documento.getPage(indicePagina), caja,
                            lineasSello(datos, credencial, ahora)));
                    opciones.setPage(indicePagina);
                }
                documento.addSignature(firma, firmadorCms, opciones);
                marcarImprimible(documento, firma);
                documento.saveIncremental(salida);
            }

            byte[] firmado = salida.toByteArray();
            return new ResultadoFirma(firmado, sha256(pdf), sha256(firmado),
                    Base64.getEncoder().encodeToString(firmadorCms.getUltimaFirma()),
                    FirmadorCms.algoritmoPara(credencial.clave()),
                    HexFormat.of().withUpperCase().formatHex(credencial.certificado().getSerialNumber().toByteArray())
                            .replaceFirst("^00", ""),
                    ahora.toOffsetDateTime().toString());
        } catch (InvalidPasswordException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El legajo está protegido con contraseña.");
        } catch (IOException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY,
                    "No se pudo firmar el legajo PDF: " + e.getMessage(), e);
        }
    }

    /** Campo de firma imprimible (requisito PDF/A). */
    private static void marcarImprimible(PDDocument documento, PDSignature firma) {
        PDAcroForm acro = documento.getDocumentCatalog().getAcroForm(null);
        if (acro == null) return;
        for (PDField campo : acro.getFieldTree()) {
            if (campo instanceof PDSignatureField sf && sf.getSignature() != null
                    && sf.getSignature().getCOSObject() == firma.getCOSObject()) {
                for (PDAnnotationWidget widget : sf.getWidgets()) {
                    widget.setPrinted(true);
                    widget.getCOSObject().setNeedToBeUpdated(true);
                }
            }
        }
    }

    /** Ubica el sello visible en la esquina inferior; cada firma adicional se apila encima. */
    private static PDRectangle cajaSello(PDPage pagina, int firmasPrevias) {
        PDRectangle media = pagina.getMediaBox();
        float x = media.getLowerLeftX() + media.getWidth() - ANCHO_SELLO - MARGEN;
        float y = media.getLowerLeftY() + MARGEN + firmasPrevias * (ALTO_SELLO + 8f);
        return new PDRectangle(Math.max(media.getLowerLeftX() + 4, x), y, ANCHO_SELLO, ALTO_SELLO);
    }

    private static List<String> lineasSello(DatosFirma datos, CredencialFirma credencial, ZonedDateTime fecha) {
        List<String> lineas = new ArrayList<>();
        lineas.add("Firmado digitalmente por:");
        lineas.add(datos.nombre());
        lineas.add(datos.leyendaVisible() != null && !datos.leyendaVisible().isBlank() ? datos.leyendaVisible() : datos.razon());
        lineas.add("Fecha: " + FORMATO.format(fecha) + " (BOT)");
        String serie = HexFormat.of().withUpperCase().formatHex(credencial.certificado().getSerialNumber().toByteArray())
                .replaceFirst("^00", "");
        lineas.add("Cert. N° " + serie + " · " + datos.ubicacion());
        return lineas;
    }

    /** Plantilla de apariencia para la firma visible (técnica de PDFBox: documento auxiliar con el widget). */
    private static InputStream plantillaVisible(PDPage paginaDestino, PDRectangle caja, List<String> lineas)
            throws IOException {
        try (PDDocument plantilla = new PDDocument()) {
            PDPage pagina = new PDPage(paginaDestino.getMediaBox());
            plantilla.addPage(pagina);
            PDAcroForm acro = new PDAcroForm(plantilla);
            plantilla.getDocumentCatalog().setAcroForm(acro);
            PDSignatureField campo = new PDSignatureField(acro);
            PDAnnotationWidget widget = campo.getWidgets().get(0);
            acro.setSignaturesExist(true);
            acro.setAppendOnly(true);
            acro.getCOSObject().setDirect(true);
            acro.getFields().add(campo);
            widget.setRectangle(caja);
            widget.setPrinted(true);

            PDStream stream = new PDStream(plantilla);
            PDFormXObject formulario = new PDFormXObject(stream);
            formulario.setResources(new PDResources());
            formulario.setFormType(1);
            formulario.setBBox(new PDRectangle(caja.getWidth(), caja.getHeight()));

            PDAppearanceDictionary apariencia = new PDAppearanceDictionary();
            apariencia.getCOSObject().setDirect(true);
            PDAppearanceStream flujo = new PDAppearanceStream(formulario.getCOSObject());
            apariencia.setNormalAppearance(flujo);
            widget.setAppearance(apariencia);

            PDType1Font normal = new PDType1Font(Standard14Fonts.FontName.HELVETICA);
            PDType1Font negrita = new PDType1Font(Standard14Fonts.FontName.HELVETICA_BOLD);
            try (PDPageContentStream cs = new PDPageContentStream(plantilla, flujo)) {
                cs.setNonStrokingColor(new Color(239, 246, 255));
                cs.addRect(0, 0, caja.getWidth(), caja.getHeight());
                cs.fill();
                cs.setStrokingColor(new Color(23, 37, 84));
                cs.setLineWidth(1f);
                cs.addRect(0.5f, 0.5f, caja.getWidth() - 1, caja.getHeight() - 1);
                cs.stroke();
                cs.setNonStrokingColor(new Color(23, 37, 84));
                cs.addRect(0, 0, 4, caja.getHeight());
                cs.fill();

                float y = caja.getHeight() - 11;
                for (int i = 0; i < lineas.size(); i++) {
                    PDType1Font fuente = i == 1 ? negrita : normal;
                    float tamano = i == 1 ? 8.5f : 7f;
                    cs.beginText();
                    cs.setNonStrokingColor(i == 0 ? new Color(71, 85, 105) : new Color(15, 23, 42));
                    cs.setFont(fuente, tamano);
                    cs.newLineAtOffset(10, y);
                    cs.showText(recortar(TextoPdf.seguro(lineas.get(i), fuente), fuente, tamano, caja.getWidth() - 16));
                    cs.endText();
                    y -= i == 1 ? 11.5f : 10f;
                }
            }

            ByteArrayOutputStream out = new ByteArrayOutputStream();
            plantilla.save(out);
            return new ByteArrayInputStream(out.toByteArray());
        }
    }

    private static String recortar(String texto, PDType1Font fuente, float tamano, float ancho) throws IOException {
        String t = texto;
        while (t.length() > 4 && fuente.getStringWidth(t) / 1000 * tamano > ancho) {
            t = t.substring(0, t.length() - 2);
        }
        return t.equals(texto) ? t : t + "…";
    }

    static String sha256(byte[] datos) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(datos));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException(e);
        }
    }
}
