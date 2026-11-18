package bo.edu.uagrm.firmador.config;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;

import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

/**
 * Solo Laravel puede invocar al microservicio: toda petición a /api/** debe traer la cabecera X-Api-Key.
 * /api/salud queda libre para comprobar que el servicio está levantado.
 */
@Component
public class ApiKeyFilter extends OncePerRequestFilter {

    private final byte[] apiKey;

    public ApiKeyFilter(FirmadorProperties props) {
        this.apiKey = props.apiKey().getBytes(StandardCharsets.UTF_8);
    }

    @Override
    protected boolean shouldNotFilter(HttpServletRequest request) {
        String ruta = request.getRequestURI();
        return !ruta.startsWith("/api/") || ruta.equals("/api/salud");
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        String recibida = request.getHeader("X-Api-Key");
        boolean valida = recibida != null
                && MessageDigest.isEqual(apiKey, recibida.getBytes(StandardCharsets.UTF_8));
        if (!valida) {
            response.setStatus(HttpServletResponse.SC_UNAUTHORIZED);
            response.setContentType("application/json;charset=UTF-8");
            response.getWriter().write("{\"message\":\"Clave de acceso al microservicio de firma inválida.\"}");
            return;
        }
        chain.doFilter(request, response);
    }
}
