package bo.edu.uagrm.firmador.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties(prefix = "firmador")
public record FirmadorProperties(
        String apiKey,
        String directorioDatos,
        String claveAc,
        String ubicacionPorDefecto) {
}
