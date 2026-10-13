export default function PageHeroBanner({
  eyebrow,
  title,
  highlight,
  description,
  align = 'left',
}) {
  const center = align === 'center';

  return (
    <section
      style={{
        background: 'linear-gradient(135deg, #0f172a 0%, #1e3a8a 58%, #2255ee 100%)',
        padding: 'clamp(3.25rem, 8vw, 5.75rem) clamp(1.5rem, 5vw, 3rem) clamp(2.75rem, 6vw, 4.5rem)',
        position: 'relative',
        overflow: 'hidden',
      }}
    >
      <div
        style={{
          position: 'absolute',
          inset: 0,
          backgroundImage: 'radial-gradient(circle at 2px 2px, rgba(255,255,255,0.06) 1px, transparent 0)',
          backgroundSize: '40px 40px',
          pointerEvents: 'none',
        }}
      />

      <div
        style={{
          position: 'absolute',
          top: '-70px',
          right: '-80px',
          width: '400px',
          height: '400px',
          background: 'radial-gradient(circle, rgba(96,165,250,0.18) 0%, transparent 70%)',
          pointerEvents: 'none',
        }}
      />

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          maxWidth: '1200px',
          margin: '0 auto',
          textAlign: center ? 'center' : 'left',
        }}
      >
        {eyebrow && (
          <div
            className="dtb-title-eyebrow"
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '8px',
              background: 'rgba(255,255,255,0.11)',
              border: '1px solid rgba(255,255,255,0.24)',
              borderRadius: '99px',
              padding: '5px 14px',
              fontSize: '0.68rem',
              fontWeight: 700,
              letterSpacing: '0.12em',
              textTransform: 'uppercase',
              color: 'rgba(255,255,255,0.84)',
              marginBottom: '18px',
            }}
          >
            {eyebrow}
          </div>
        )}

        <h1
          style={{
            color: 'white',
            fontSize: 'clamp(2rem, 5.2vw, 3.7rem)',
            fontWeight: 900,
            margin: '0 0 14px',
            lineHeight: 1.08,
            letterSpacing: '-0.03em',
          }}
        >
          {title}
          {highlight ? (
            <>
              <br />
              <span style={{ color: '#93c5fd' }}>{highlight}</span>
            </>
          ) : null}
        </h1>

        {description && (
          <p
            style={{
              color: 'rgba(255,255,255,0.68)',
              fontSize: 'clamp(0.95rem, 2vw, 1.08rem)',
              margin: center ? '0 auto' : '0',
              lineHeight: 1.6,
              maxWidth: '640px',
            }}
          >
            {description}
          </p>
        )}
      </div>
    </section>
  );
}
