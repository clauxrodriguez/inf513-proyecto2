package bo.edu.uagrm.firmador.ac;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.io.StringWriter;
import java.math.BigInteger;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.GeneralSecurityException;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.SecureRandom;
import java.security.UnrecoverableKeyException;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.temporal.ChronoUnit;
import java.util.Arrays;
import java.util.Date;
import java.util.HashMap;
import java.util.HexFormat;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Pattern;

import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.asn1.x500.X500NameBuilder;
import org.bouncycastle.asn1.x500.style.BCStyle;
import org.bouncycastle.asn1.x500.style.IETFUtils;
import org.bouncycastle.asn1.x509.BasicConstraints;
import org.bouncycastle.asn1.x509.Extension;
import org.bouncycastle.asn1.x509.GeneralName;
import org.bouncycastle.asn1.x509.GeneralNames;
import org.bouncycastle.asn1.x509.KeyUsage;
import org.bouncycastle.cert.X509v3CertificateBuilder;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cert.jcajce.JcaX509ExtensionUtils;
import org.bouncycastle.cert.jcajce.JcaX509v3CertificateBuilder;
import org.bouncycastle.openssl.jcajce.JcaPEMWriter;
import org.bouncycastle.operator.ContentSigner;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Component;

import bo.edu.uagrm.firmador.config.FirmadorProperties;
import bo.edu.uagrm.firmador.web.FirmadorException;
import jakarta.annotation.PostConstruct;

/**
 * Autoridad Certificadora de PRUEBA. Ocupa el lugar que en producción tendría ADSIB:
 * emite los certificados X.509 de las autoridades firmantes y los entrega en un .p12 protegido con PIN
 * (equivalente académico del token criptográfico). No tiene validez legal.
 */
@Component
public class AutoridadCertificadora {

    private static final Logger log = LoggerFactory.getLogger(AutoridadCertificadora.class);
    private static final String ALIAS = "firmante";
    private static final String ALGORITMO_FIRMA_CERT = "SHA256withRSA";
    private static final Pattern SERIE_VALIDA = Pattern.compile("^[0-9A-F]{8,40}$");
    private static final ZoneId ZONA = ZoneId.of("America/La_Paz");

    private final Path dirAc;
    private final Path dirCertificados;
    private final char[] claveAc;
    private final SecureRandom aleatorio = new SecureRandom();

    public static final String SELLO_RECTORADO = "RECTORADO";
    public static final String SELLO_VICERRECTORADO = "VICERRECTORADO";

    private PrivateKey claveRaiz;
    private X509Certificate certRaiz;
    private final Map<String, CredencialFirma> sellos = new HashMap<>();

    public AutoridadCertificadora(FirmadorProperties props) {
        Path base = Path.of(props.directorioDatos()).toAbsolutePath().normalize();
        this.dirAc = base.resolve("ac");
        this.dirCertificados = base.resolve("certificados");
        this.claveAc = props.claveAc().toCharArray();
    }

    @PostConstruct
    void inicializar() throws Exception {
        Files.createDirectories(dirAc);
        Files.createDirectories(dirCertificados);

        Path archivoRaiz = dirAc.resolve("ac-raiz.p12");
        if (Files.exists(archivoRaiz)) {
            CredencialFirma raiz = cargar(archivoRaiz, claveAc.clone());
            claveRaiz = raiz.clave();
            certRaiz = raiz.certificado();
        } else {
            crearRaiz(archivoRaiz);
            log.info("AC raíz de prueba creada en {}", archivoRaiz);
        }

        sellos.put(SELLO_RECTORADO, cargarOCrearSello("sello-institucional.p12",
                "Sello Institucional UAGRM - Rectorado", "Rectorado", "rectorado@uagrm.edu.bo"));
        sellos.put(SELLO_VICERRECTORADO, cargarOCrearSello("sello-vicerrectorado.p12",
                "Sello Electronico Vicerrectorado UAGRM", "Vicerrectorado", "vicerrectorado@uagrm.edu.bo"));
        log.info("AC de prueba lista: {}", certRaiz.getSubjectX500Principal().getName());
    }

    /** Sello electrónico institucional (no requiere PIN de una persona: lo custodia el sistema). */
    private CredencialFirma cargarOCrearSello(String archivo, String nombre, String unidad, String email) throws Exception {
        Path ruta = dirAc.resolve(archivo);
        if (!Files.exists(ruta)) {
            emitirEnArchivo(nombre(nombre, unidad, "SELLO INSTITUCIONAL", email), email, 3650, ruta, claveAc.clone());
            log.info("Certificado de sello institucional creado en {}", ruta);
        }
        return cargar(ruta, claveAc.clone());
    }

    /** Emite el certificado de una autoridad y guarda su .p12 protegido con el PIN. */
    public CertificadoEmitido emitir(String nombreCompleto, String email, String cargo, String unidad,
                                     int diasVigencia, char[] pin) {
        try {
            X500Name sujeto = nombre(nombreCompleto, unidad, cargo, email);
            CertificadoEmitido emitido = emitirEnArchivo(sujeto, email, diasVigencia, null, pin);
            log.info("Certificado {} emitido para {}", emitido.numeroSerie(), nombreCompleto);
            return emitido;
        } catch (GeneralSecurityException | IOException | org.bouncycastle.operator.OperatorCreationException e) {
            throw new FirmadorException(HttpStatus.INTERNAL_SERVER_ERROR, "No se pudo emitir el certificado digital.", e);
        } finally {
            Arrays.fill(pin, '\0');
        }
    }

    /** Abre el .p12 del firmante con su PIN. El PIN se borra de memoria al terminar. */
    public CredencialFirma abrirCredencial(String numeroSerie, char[] pin) {
        try {
            String serie = numeroSerie == null ? "" : numeroSerie.trim().toUpperCase(Locale.ROOT);
            if (!SERIE_VALIDA.matcher(serie).matches()) {
                throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "Número de serie de certificado inválido.");
            }
            Path archivo = dirCertificados.resolve(serie + ".p12");
            if (!Files.exists(archivo)) {
                throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY,
                        "El certificado " + serie + " no tiene llave privada en el firmador. "
                                + "Solicite a Rectorado la emisión de un nuevo certificado digital.");
            }
            return cargar(archivo, pin);
        } catch (IOException e) {
            if (e.getCause() instanceof UnrecoverableKeyException || esErrorDeClave(e)) {
                throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "PIN del certificado digital incorrecto.");
            }
            throw new FirmadorException(HttpStatus.INTERNAL_SERVER_ERROR, "No se pudo abrir el certificado digital.", e);
        } catch (GeneralSecurityException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "PIN del certificado digital incorrecto.");
        } finally {
            Arrays.fill(pin, '\0');
        }
    }

    /** @param clave RECTORADO (visado final, CU5) o VICERRECTORADO (certificado oficial, CU6) */
    public CredencialFirma sello(String clave) {
        CredencialFirma sello = sellos.get(clave == null ? SELLO_RECTORADO : clave.toUpperCase(Locale.ROOT));
        if (sello == null) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "Sello institucional desconocido: " + clave);
        }
        return sello;
    }

    public X509Certificate certificadoRaiz() {
        return certRaiz;
    }

    public String nombreEmisor() {
        return IETFUtils.valueToString(X500Name.getInstance(certRaiz.getSubjectX500Principal().getEncoded())
                .getRDNs(BCStyle.CN)[0].getFirst().getValue());
    }

    public String certificadoRaizPem() {
        return aPem(certRaiz);
    }

    /** true si el certificado fue emitido por esta AC (cadena de confianza local). */
    public boolean emitidoPorEstaAc(X509Certificate certificado) {
        try {
            certificado.verify(certRaiz.getPublicKey());
            return true;
        } catch (GeneralSecurityException e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ internos

    private void crearRaiz(Path destino) throws Exception {
        KeyPair par = generarPar(4096);
        X500Name sujeto = new X500NameBuilder(BCStyle.INSTANCE)
                .addRDN(BCStyle.C, "BO")
                .addRDN(BCStyle.O, "Universidad Autonoma Gabriel Rene Moreno")
                .addRDN(BCStyle.OU, "Proyecto Academico INF513 - simula a ADSIB")
                .addRDN(BCStyle.CN, "AC Raiz de Prueba UAGRM")
                .build();
        Instant ahora = Instant.now();
        JcaX509ExtensionUtils utils = new JcaX509ExtensionUtils();
        X509v3CertificateBuilder builder = new JcaX509v3CertificateBuilder(sujeto, nuevaSerie(),
                Date.from(ahora.minus(1, ChronoUnit.DAYS)), Date.from(ahora.plus(3650, ChronoUnit.DAYS)),
                sujeto, par.getPublic())
                .addExtension(Extension.basicConstraints, true, new BasicConstraints(true))
                .addExtension(Extension.keyUsage, true, new KeyUsage(KeyUsage.keyCertSign | KeyUsage.cRLSign))
                .addExtension(Extension.subjectKeyIdentifier, false, utils.createSubjectKeyIdentifier(par.getPublic()));
        ContentSigner firmante = new JcaContentSignerBuilder(ALGORITMO_FIRMA_CERT).build(par.getPrivate());
        certRaiz = new JcaX509CertificateConverter().getCertificate(builder.build(firmante));
        claveRaiz = par.getPrivate();
        guardar(destino, claveRaiz, new Certificate[]{certRaiz}, claveAc.clone());
        Files.writeString(dirAc.resolve("ac-raiz.crt"), aPem(certRaiz));
    }

    private CertificadoEmitido emitirEnArchivo(X500Name sujeto, String email, int diasVigencia, Path destinoFijo,
                                               char[] pin) throws GeneralSecurityException, IOException,
            org.bouncycastle.operator.OperatorCreationException {
        KeyPair par = generarPar(2048);
        BigInteger serie = nuevaSerie();
        Instant ahora = Instant.now();
        Instant expira = ahora.plus(diasVigencia, ChronoUnit.DAYS);
        JcaX509ExtensionUtils utils = new JcaX509ExtensionUtils();
        X509v3CertificateBuilder builder = new JcaX509v3CertificateBuilder(certRaiz, serie,
                Date.from(ahora.minus(5, ChronoUnit.MINUTES)), Date.from(expira), sujeto, par.getPublic())
                .addExtension(Extension.basicConstraints, true, new BasicConstraints(false))
                .addExtension(Extension.keyUsage, true,
                        new KeyUsage(KeyUsage.digitalSignature | KeyUsage.nonRepudiation))
                .addExtension(Extension.subjectKeyIdentifier, false, utils.createSubjectKeyIdentifier(par.getPublic()))
                .addExtension(Extension.authorityKeyIdentifier, false, utils.createAuthorityKeyIdentifier(certRaiz));
        if (email != null && !email.isBlank()) {
            builder.addExtension(Extension.subjectAlternativeName, false,
                    new GeneralNames(new GeneralName(GeneralName.rfc822Name, email)));
        }
        X509Certificate certificado = new JcaX509CertificateConverter()
                .getCertificate(builder.build(new JcaContentSignerBuilder(ALGORITMO_FIRMA_CERT).build(claveRaiz)));

        String numeroSerie = HexFormat.of().withUpperCase().formatHex(serie.toByteArray()).replaceFirst("^00", "");
        Path destino = destinoFijo != null ? destinoFijo : dirCertificados.resolve(numeroSerie + ".p12");
        guardar(destino, par.getPrivate(), new Certificate[]{certificado, certRaiz}, pin);

        return new CertificadoEmitido(numeroSerie, nombreEmisor(), sujeto.toString(),
                LocalDate.ofInstant(ahora, ZONA), LocalDate.ofInstant(expira, ZONA), aPem(certificado));
    }

    private static X500Name nombre(String cn, String unidad, String cargo, String email) {
        X500NameBuilder b = new X500NameBuilder(BCStyle.INSTANCE)
                .addRDN(BCStyle.C, "BO")
                .addRDN(BCStyle.O, "Universidad Autonoma Gabriel Rene Moreno");
        if (unidad != null && !unidad.isBlank()) b.addRDN(BCStyle.OU, unidad);
        if (cargo != null && !cargo.isBlank()) b.addRDN(BCStyle.T, cargo);
        b.addRDN(BCStyle.CN, cn);
        if (email != null && !email.isBlank()) b.addRDN(BCStyle.EmailAddress, email);
        return b.build();
    }

    private void guardar(Path destino, PrivateKey clave, Certificate[] cadena, char[] contrasena)
            throws GeneralSecurityException, IOException {
        try {
            KeyStore ks = KeyStore.getInstance("PKCS12");
            ks.load(null, null);
            ks.setKeyEntry(ALIAS, clave, contrasena, cadena);
            try (OutputStream out = Files.newOutputStream(destino)) {
                ks.store(out, contrasena);
            }
        } finally {
            Arrays.fill(contrasena, '\0');
        }
    }

    private CredencialFirma cargar(Path archivo, char[] contrasena) throws GeneralSecurityException, IOException {
        try (InputStream in = Files.newInputStream(archivo)) {
            KeyStore ks = KeyStore.getInstance("PKCS12");
            ks.load(in, contrasena);
            PrivateKey clave = (PrivateKey) ks.getKey(ALIAS, contrasena);
            Certificate[] cadena = ks.getCertificateChain(ALIAS);
            if (clave == null || cadena == null) {
                throw new GeneralSecurityException("El almacén no contiene la entrada del firmante");
            }
            return new CredencialFirma(clave, cadena);
        } finally {
            Arrays.fill(contrasena, '\0');
        }
    }

    private static boolean esErrorDeClave(IOException e) {
        String m = String.valueOf(e.getMessage()).toLowerCase(Locale.ROOT);
        return m.contains("password") || m.contains("mac") || m.contains("keystore");
    }

    private KeyPair generarPar(int bits) throws GeneralSecurityException {
        KeyPairGenerator gen = KeyPairGenerator.getInstance("RSA");
        gen.initialize(bits, aleatorio);
        return gen.generateKeyPair();
    }

    private BigInteger nuevaSerie() {
        return new BigInteger(64, aleatorio).setBit(63);
    }

    private static String aPem(X509Certificate cert) {
        try (StringWriter sw = new StringWriter(); JcaPEMWriter pem = new JcaPEMWriter(sw)) {
            pem.writeObject(cert);
            pem.flush();
            return sw.toString();
        } catch (IOException e) {
            throw new IllegalStateException(e);
        }
    }
}
