// Avatar SVG abstracta de Athena — perfil femenino en línea fina,
// inspirado en arte griego clásico (Athena es diosa griega).
// Paleta lino cálido — tonos warm beige + sepia.
// Reemplazable: si Isabel quiere una foto real generada con AI,
// solo hay que reemplazar este componente por <img src={url} />.

export default function AthenaAvatar({ size = 64, className = '' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-label="Athena"
    >
      {/* Círculo de fondo en lino warm */}
      <circle cx="50" cy="50" r="49" fill="#f4ede1" stroke="#c9b896" strokeWidth="1" />

      {/* Perfil femenino — línea fina, estilo art deco / griego clásico */}
      <g fill="none" stroke="#8b6f47" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        {/* Cabello (recogido elegante) */}
        <path d="M 30 38 Q 28 28 34 22 Q 42 16 52 18 Q 62 20 66 28 Q 70 36 68 44" />
        <path d="M 66 28 Q 70 22 74 26 Q 76 32 72 38" />

        {/* Frente y nariz (perfil) */}
        <path d="M 36 42 Q 36 50 38 56 Q 39 60 40 62" />
        <path d="M 40 62 Q 41 64 43 64 Q 44 64 44 62" />

        {/* Labios */}
        <path d="M 44 66 Q 41 67 39 67" />
        <path d="M 44 70 Q 41 71 38 70" />

        {/* Barbilla y cuello */}
        <path d="M 38 73 Q 38 78 40 80 Q 42 82 44 82" />
        <path d="M 44 82 L 44 90" />

        {/* Ojo (almendrado, sutil) */}
        <path d="M 41 54 Q 44 53 47 54" />

        {/* Ceja */}
        <path d="M 41 50 Q 44 49 47 50" />

        {/* Arete pequeño (gold dot) */}
        <circle cx="38" cy="68" r="0.8" fill="#c9a567" stroke="none" />

        {/* Cuello / clavícula sutil */}
        <path d="M 44 88 Q 50 90 56 88" />

        {/* Hombros parcial */}
        <path d="M 56 88 Q 64 92 68 96" opacity="0.6" />

        {/* Rama de olivo (símbolo de Athena) — pequeña, detrás del perfil */}
        <g opacity="0.4">
          <path d="M 72 50 Q 76 46 80 48" />
          <ellipse cx="74" cy="48" rx="1.5" ry="0.8" transform="rotate(-30 74 48)" />
          <ellipse cx="77" cy="46" rx="1.5" ry="0.8" transform="rotate(-30 77 46)" />
          <ellipse cx="79" cy="48" rx="1.5" ry="0.8" transform="rotate(-30 79 48)" />
        </g>
      </g>
    </svg>
  );
}
