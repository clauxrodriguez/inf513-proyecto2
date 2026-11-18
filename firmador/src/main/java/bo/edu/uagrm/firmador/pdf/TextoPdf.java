package bo.edu.uagrm.firmador.pdf;

import org.apache.pdfbox.pdmodel.font.PDFont;

/** Las fuentes estándar (WinAnsi) no cubren todos los caracteres: se reemplazan los que no se pueden codificar. */
final class TextoPdf {

    private TextoPdf() {
    }

    static String seguro(String texto, PDFont fuente) {
        if (texto == null) return "";
        StringBuilder sb = new StringBuilder(texto.length());
        texto.codePoints().forEach(cp -> {
            String c = new String(Character.toChars(cp));
            if (cp == '\n' || cp == '\r' || cp == '\t') {
                sb.append(' ');
                return;
            }
            try {
                fuente.encode(c);
                sb.append(c);
            } catch (Exception e) {
                sb.append('?');
            }
        });
        return sb.toString();
    }
}
