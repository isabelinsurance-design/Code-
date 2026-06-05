// Avatar de Athena — foto real de Isabel (su versión "elegant Latina executive").
// Sirve dos formatos: webp (más ligero, ~42KB) con fallback jpg.
// Si necesitas reemplazarla por otra foto, sustituye los archivos en
// app-v2/public/athena.{webp,jpg} y rebuild.

export default function AthenaAvatar({ size = 64, className = '' }) {
  return (
    <picture>
      <source srcSet="/app/athena.webp" type="image/webp" />
      <img
        src="/app/athena.jpg"
        width={size}
        height={size}
        alt="Athena"
        className={`rounded-full object-cover ${className}`}
        style={{ width: size, height: size }}
        loading="eager"
        decoding="async"
      />
    </picture>
  );
}
