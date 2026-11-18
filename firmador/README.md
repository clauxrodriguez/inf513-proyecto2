# Firmador — Microservicio de firma digital (Java 17 · Spring Boot 3.4)

Servicio interno de la capa de negocio. **Solo lo llama Laravel**: escucha en `127.0.0.1` y exige la cabecera `X-Api-Key`. Usa **Apache PDFBox 3** y **Bouncy Castle**.

| Función | Detalle |
|---|---|
| **AC de prueba** (ocupa el lugar de ADSIB) | Crea la AC raíz y emite los certificados X.509 de las autoridades en archivos `.p12` protegidos con PIN. Mantiene además los sellos institucionales de Rectorado y del Vicerrectorado. |
| **Legajo foliado** | Une los PDF e imágenes del trámite, con carátula y "Foja X de N" en cada página. |
| **Firma PAdES** | `ETSI.CAdES.detached` con `signing-certificate-v2` y sin `signing-time` (la hora va en `/M`). Usa SHA256withRSA o ECDSA, incrusta la cadena completa y guarda en forma incremental, con el campo de firma imprimible. El PIN se borra de memoria tras abrir el `.p12`. |
| **Certificado oficial** | Genera el PDF/A-2b (fuentes incrustadas, sRGB, metadatos XMP) con el código QR, firmado con el sello del Vicerrectorado. |
| **Verificación** | Comprueba la integridad, la cadena de confianza, la vigencia del certificado y la cobertura del documento en cada firma. |

## Ejecutar

```bash
./mvnw spring-boot:run              # Windows: .\mvnw.cmd spring-boot:run
# o compilar y ejecutar el .jar:
./mvnw package -DskipTests && java -jar target/firmador.jar
```
`GET http://127.0.0.1:8081/api/salud` responde `{"estado":"OK", ...}`.

## Endpoints (`/api`, cabecera `X-Api-Key`)

| Método y ruta | Uso |
|---|---|
| `GET /salud` | Estado (no requiere clave) |
| `GET /ac/certificado` | Certificado raíz (PEM) para marcarlo como confiable en Adobe Reader |
| `POST /certificados` | Emite el certificado de una autoridad (`nombre`, `email`, `cargo`, `unidad`, `diasVigencia`, `pin`) |
| `POST /legajos` | Compila el legajo foliado (multipart: `metadatos` + `archivos[]`) |
| `POST /firmas` | Firma PAdES con el `.p12` del firmante (`numeroSerie` + `pin`) |
| `POST /sellos` | Sello institucional incremental (por defecto el de Rectorado) |
| `POST /certificados-oficiales` | Certificado o constancia PDF/A con QR, sellado |
| `POST /verificaciones` | Verifica las firmas incrustadas en un PDF |

## Configuración

En [`application.properties`](src/main/resources/application.properties) o como variables de entorno:

| Propiedad (variable) | Valor por defecto |
|---|---|
| `server.port` (`FIRMADOR_PUERTO`) | `8081` |
| `firmador.api-key` (`FIRMADOR_API_KEY`) | `clave-local-firmador-inf513`. Debe coincidir con `backend/.env`. |
| `firmador.directorio-datos` (`FIRMADOR_DATOS`) | `./datos` |
| `firmador.clave-ac` (`FIRMADOR_CLAVE_AC`) | Contraseña de los almacenes de la AC y de los sellos |

La carpeta **`datos/`** contiene las **llaves privadas** de la AC, los sellos y los `.p12` de los firmantes. Se genera sola y **está excluida de git**. Si la borras, se crea una AC nueva y los certificados emitidos antes dejan de poder firmar: habrá que emitirlos de nuevo desde *Delegaciones*.

Fuentes DejaVu incluidas bajo su licencia: [`src/main/resources/fuentes/LICENSE-DejaVu.txt`](src/main/resources/fuentes/LICENSE-DejaVu.txt).
