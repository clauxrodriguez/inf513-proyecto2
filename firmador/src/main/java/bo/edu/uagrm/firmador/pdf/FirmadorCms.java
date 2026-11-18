package bo.edu.uagrm.firmador.pdf;

import java.io.IOException;
import java.io.InputStream;
import java.security.MessageDigest;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.util.Arrays;
import java.util.Map;

import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureInterface;
import org.bouncycastle.asn1.ASN1EncodableVector;
import org.bouncycastle.asn1.ASN1Encoding;
import org.bouncycastle.asn1.DERSet;
import org.bouncycastle.asn1.cms.Attribute;
import org.bouncycastle.asn1.cms.AttributeTable;
import org.bouncycastle.asn1.cms.CMSAttributes;
import org.bouncycastle.asn1.ess.ESSCertIDv2;
import org.bouncycastle.asn1.ess.SigningCertificateV2;
import org.bouncycastle.asn1.nist.NISTObjectIdentifiers;
import org.bouncycastle.asn1.pkcs.PKCSObjectIdentifiers;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.asn1.x509.AlgorithmIdentifier;
import org.bouncycastle.asn1.x509.GeneralName;
import org.bouncycastle.asn1.x509.GeneralNames;
import org.bouncycastle.asn1.x509.IssuerSerial;
import org.bouncycastle.cert.jcajce.JcaCertStore;
import org.bouncycastle.cms.CMSAttributeTableGenerator;
import org.bouncycastle.cms.CMSProcessableByteArray;
import org.bouncycastle.cms.CMSSignedData;
import org.bouncycastle.cms.CMSSignedDataGenerator;
import org.bouncycastle.cms.DefaultSignedAttributeTableGenerator;
import org.bouncycastle.cms.SignerInfoGenerator;
import org.bouncycastle.cms.jcajce.JcaSignerInfoGeneratorBuilder;
import org.bouncycastle.operator.ContentSigner;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.bouncycastle.operator.jcajce.JcaDigestCalculatorProviderBuilder;

/**
 * Genera la firma CMS desacoplada (ETSI.CAdES.detached) que PDFBox incrusta en el PDF.
 * - Atributo firmado signing-certificate-v2 (exigido por PAdES baseline).
 * - Sin signing-time: en PAdES la hora va en el diccionario de la firma (/M).
 * - SHA256withRSA o SHA256withECDSA según el tipo de llave.
 * - Cadena de certificados completa incrustada.
 */
class FirmadorCms implements SignatureInterface {

    private final PrivateKey clave;
    private final Certificate[] cadena;
    private byte[] ultimaFirma;

    FirmadorCms(PrivateKey clave, Certificate[] cadena) {
        this.clave = clave;
        this.cadena = cadena;
    }

    static String algoritmoPara(PrivateKey clave) {
        return "EC".equalsIgnoreCase(clave.getAlgorithm()) ? "SHA256withECDSA" : "SHA256withRSA";
    }

    @Override
    public byte[] sign(InputStream contenido) throws IOException {
        try {
            X509Certificate certificado = (X509Certificate) cadena[0];
            ContentSigner firmante = new JcaContentSignerBuilder(algoritmoPara(clave)).build(clave);

            byte[] hashCertificado = MessageDigest.getInstance("SHA-256").digest(certificado.getEncoded());
            IssuerSerial emisorSerie = new IssuerSerial(
                    new GeneralNames(new GeneralName(X500Name.getInstance(certificado.getIssuerX500Principal().getEncoded()))),
                    certificado.getSerialNumber());
            ESSCertIDv2 idCertificado = new ESSCertIDv2(new AlgorithmIdentifier(NISTObjectIdentifiers.id_sha256),
                    hashCertificado, emisorSerie);
            ASN1EncodableVector atributos = new ASN1EncodableVector();
            atributos.add(new Attribute(PKCSObjectIdentifiers.id_aa_signingCertificateV2,
                    new DERSet(new SigningCertificateV2(new ESSCertIDv2[]{idCertificado}))));

            CMSAttributeTableGenerator generadorAtributos = new DefaultSignedAttributeTableGenerator(
                    new AttributeTable(atributos)) {
                @Override
                @SuppressWarnings("rawtypes")
                public AttributeTable getAttributes(Map parametros) {
                    return super.getAttributes(parametros).remove(CMSAttributes.signingTime);
                }
            };

            SignerInfoGenerator infoFirmante = new JcaSignerInfoGeneratorBuilder(
                    new JcaDigestCalculatorProviderBuilder().build())
                    .setSignedAttributeGenerator(generadorAtributos)
                    .build(firmante, certificado);

            CMSSignedDataGenerator generador = new CMSSignedDataGenerator();
            generador.addSignerInfoGenerator(infoFirmante);
            generador.addCertificates(new JcaCertStore(Arrays.asList(cadena)));

            CMSSignedData cms = generador.generate(new CMSProcessableByteArray(contenido.readAllBytes()), false);
            ultimaFirma = cms.getEncoded(ASN1Encoding.DER);
            return ultimaFirma;
        } catch (Exception e) {
            throw new IOException("No se pudo generar la firma CMS: " + e.getMessage(), e);
        }
    }

    byte[] getUltimaFirma() {
        return ultimaFirma;
    }
}
