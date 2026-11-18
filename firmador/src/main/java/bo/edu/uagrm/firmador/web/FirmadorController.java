package bo.edu.uagrm.firmador.web;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RequestPart;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;

import com.fasterxml.jackson.databind.ObjectMapper;

import bo.edu.uagrm.firmador.ac.AutoridadCertificadora;
import bo.edu.uagrm.firmador.ac.CertificadoEmitido;
import bo.edu.uagrm.firmador.ac.CredencialFirma;
import bo.edu.uagrm.firmador.config.FirmadorProperties;
import bo.edu.uagrm.firmador.pdf.CertificadoOficialService;
import bo.edu.uagrm.firmador.pdf.CompiladorLegajoService;
import bo.edu.uagrm.firmador.pdf.DatosCertificadoOficial;
import bo.edu.uagrm.firmador.pdf.DatosFirma;
import bo.edu.uagrm.firmador.pdf.FirmaPadesService;
import bo.edu.uagrm.firmador.pdf.MetadatosLegajo;
import bo.edu.uagrm.firmador.pdf.ResultadoFirma;
import bo.edu.uagrm.firmador.pdf.VerificadorFirmaService;

@RestController
@RequestMapping("/api")
public class FirmadorController {

    private final AutoridadCertificadora ac;
    private final FirmaPadesService firmaPades;
    private final VerificadorFirmaService verificador;
    private final CompiladorLegajoService compilador;
    private final CertificadoOficialService certificados;
    private final ObjectMapper json;
    private final FirmadorProperties props;

    public FirmadorController(AutoridadCertificadora ac, FirmaPadesService firmaPades, VerificadorFirmaService verificador,
                              CompiladorLegajoService compilador, CertificadoOficialService certificados,
                              ObjectMapper json, FirmadorProperties props) {
        this.ac = ac;
        this.firmaPades = firmaPades;
        this.verificador = verificador;
        this.compilador = compilador;
        this.certificados = certificados;
        this.json = json;
        this.props = props;
    }

    @GetMapping("/salud")
    public Map<String, String> salud() {
        return Map.of("estado", "OK", "autoridadCertificadora", ac.nombreEmisor());
    }

    /** Certificado raíz de la AC de prueba, para instalarlo como confiable en el lector de PDF. */
    @GetMapping(value = "/ac/certificado")
    public ResponseEntity<byte[]> certificadoRaiz() {
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"ac-raiz-prueba-uagrm.crt\"")
                .contentType(MediaType.parseMediaType("application/x-x509-ca-cert"))
                .body(ac.certificadoRaizPem().getBytes(StandardCharsets.US_ASCII));
    }

    public record SolicitudCertificado(String nombre, String email, String cargo, String unidad,
                                       Integer diasVigencia, String pin) {
    }

    /** Emite el certificado de una autoridad (lo que en producción haría ADSIB). */
    @PostMapping("/certificados")
    public ResponseEntity<CertificadoEmitido> emitir(@RequestBody SolicitudCertificado s) {
        if (s.nombre() == null || s.nombre().isBlank()) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El nombre del titular del certificado es obligatorio.");
        }
        if (s.pin() == null || s.pin().length() < 6) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El PIN del certificado debe tener al menos 6 caracteres.");
        }
        int dias = s.diasVigencia() == null ? 365 : s.diasVigencia();
        if (dias < 1 || dias > 1825) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "La vigencia del certificado debe estar entre 1 y 1825 días.");
        }
        CertificadoEmitido emitido = ac.emitir(s.nombre().trim(), s.email(), s.cargo(), s.unidad(), dias, s.pin().toCharArray());
        return ResponseEntity.status(HttpStatus.CREATED).body(emitido);
    }

    /** Firma PAdES de un legajo con el certificado (.p12) del firmante, abierto con su PIN. */
    @PostMapping(value = "/firmas", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public Map<String, Object> firmar(@RequestPart("pdf") MultipartFile pdf,
                                      @RequestParam("numeroSerie") String numeroSerie,
                                      @RequestParam("pin") String pin,
                                      @RequestParam("nombre") String nombre,
                                      @RequestParam("razon") String razon,
                                      @RequestParam(value = "ubicacion", required = false) String ubicacion,
                                      @RequestParam(value = "contacto", required = false) String contacto,
                                      @RequestParam(value = "leyenda", required = false) String leyenda) throws IOException {
        char[] pinChars = pin.toCharArray();
        CredencialFirma credencial = ac.abrirCredencial(numeroSerie, pinChars); // borra el PIN tras abrir el .p12
        DatosFirma datos = new DatosFirma(nombre, razon, ubicacionODefecto(ubicacion), contacto, leyenda);
        return respuesta(firmaPades.firmar(leerPdf(pdf), credencial, datos));
    }

    /** CU5 SF-2: sello institucional (por defecto Rectorado) como firma incremental sobre el legajo ya firmado. */
    @PostMapping(value = "/sellos", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public Map<String, Object> sellar(@RequestPart("pdf") MultipartFile pdf,
                                      @RequestParam("nombre") String nombre,
                                      @RequestParam("razon") String razon,
                                      @RequestParam(value = "ubicacion", required = false) String ubicacion,
                                      @RequestParam(value = "contacto", required = false) String contacto,
                                      @RequestParam(value = "leyenda", required = false) String leyenda,
                                      @RequestParam(value = "sello", required = false) String sello) throws IOException {
        DatosFirma datos = new DatosFirma(nombre, razon, ubicacionODefecto(ubicacion), contacto, leyenda);
        return respuesta(firmaPades.firmar(leerPdf(pdf), ac.sello(sello), datos));
    }

    /**
     * CU6 / RF-14: genera el certificado oficial (CL-) o constancia (CD-) en PDF/A-2b con QR y lo firma
     * con el sello electrónico del Vicerrectorado (firma invisible para conservar la conformidad PDF/A).
     */
    @PostMapping("/certificados-oficiales")
    public Map<String, Object> certificadoOficial(@RequestBody DatosCertificadoOficial datos) {
        if (datos.numero() == null || datos.codigoVerificacion() == null || datos.hashExpediente() == null
                || datos.qrContenido() == null) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY,
                    "El certificado requiere número, código de verificación, hash del expediente y contenido del QR.");
        }
        byte[] pdf = certificados.generar(datos);
        DatosFirma firma = new DatosFirma("Sello Electrónico Vicerrectorado UAGRM",
                "Emisión del " + (datos.esConstancia() ? "constancia " : "certificado oficial ") + datos.numero()
                        + ". " + datos.leyendaFirma(),
                props.ubicacionPorDefecto() + " (Vicerrectorado UAGRM)", "vicerrectorado@uagrm.edu.bo", null, false);
        return respuesta(firmaPades.firmar(pdf, ac.sello(AutoridadCertificadora.SELLO_VICERRECTORADO), firma));
    }

    @PostMapping(value = "/verificaciones", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public VerificadorFirmaService.ResultadoVerificacion verificar(@RequestPart("pdf") MultipartFile pdf) throws IOException {
        return verificador.verificar(leerPdf(pdf));
    }

    /** Compila el legajo foliado. Devuelve el PDF; el total de fojas y el hash van en cabeceras. */
    @PostMapping(value = "/legajos", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<byte[]> compilar(@RequestParam("metadatos") String metadatos,
                                           @RequestPart(value = "archivos[]", required = false) List<MultipartFile> archivos)
            throws IOException {
        MetadatosLegajo meta = json.readValue(metadatos, MetadatosLegajo.class);
        CompiladorLegajoService.LegajoCompilado legajo = compilador.compilar(meta, archivos);
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header("X-Total-Fojas", String.valueOf(legajo.totalFojas()))
                .header("X-Hash-Sha256", legajo.hashSha256())
                .body(legajo.pdf());
    }

    private String ubicacionODefecto(String ubicacion) {
        return ubicacion == null || ubicacion.isBlank() ? props.ubicacionPorDefecto() : ubicacion.trim();
    }

    private static byte[] leerPdf(MultipartFile pdf) throws IOException {
        if (pdf == null || pdf.isEmpty()) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "No se recibió el PDF a procesar.");
        }
        return pdf.getBytes();
    }

    private static Map<String, Object> respuesta(ResultadoFirma r) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("pdfFirmado", Base64.getEncoder().encodeToString(r.pdfFirmado()));
        m.put("hashDocumento", r.hashDocumento());
        m.put("hashFirmado", r.hashFirmado());
        m.put("firmaCms", r.firmaCms());
        m.put("algoritmo", r.algoritmo());
        m.put("numeroSerieCertificado", r.numeroSerieCertificado());
        m.put("fechaFirma", r.fechaFirma());
        return m;
    }
}
