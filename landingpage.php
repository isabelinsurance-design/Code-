<?php
// ── LANGUAGE DETECTION ────────────────────────────────────────────────────────
function detectLanguage() {
    if (isset($_COOKIE['pLang']) && in_array($_COOKIE['pLang'], ['en','es'])) return $_COOKIE['pLang'];
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
    return (stripos($accept,'es')===0 || (strpos($accept,'es-')!==false && strpos($accept,'en')===false)) ? 'es' : 'en';
}
$lang = detectLanguage();

// ── TRANSLATIONS ──────────────────────────────────────────────────────────────
$T = [
'en' => [
  'page_title'     => 'Medicare with Isabel | Isabel Fuentes',
  'nav_about'      => 'About',
  'nav_services'   => 'Services',
  'nav_reviews'    => 'Reviews',
  'nav_contact'    => 'Contact',
  'hero_h1'        => 'Medicare Made<br><em>Simple &amp; Personal</em>',
  'hero_sub'       => "Hi, I'm Isabel Fuentes. I help you find the right Medicare plan — in English or Spanish — with no cost to you for my services.",
  'hero_cta1'      => '📞 Call Us Today',
  'hero_cta2'      => 'See How We Help →',
  'hero_cta3'      => '📋 Get a Free Quote',
  'hero_card_sub'  => 'Licensed Medicare Insurance Agent · Southern California',
  'stat1'          => 'Years Helping',
  'stat2'          => 'Carriers',
  // CMS: "Free" is not permitted. "No-Cost Service" correctly reflects
  // that agent compensation comes from carriers, not beneficiaries.
  'stat3'          => 'No-Cost Service',
  'stat4'          => 'Hablamos Español',
  // ── CARRIERS SECTION ──
  'carriers_section_label' => 'Carriers We Work With',
  'carriers_section_sub'   => 'We partner with these leading carriers to find you the best fit — <strong>we do not represent every plan available in your area</strong>.',
  // ── ABOUT ──
  'about_label'    => 'About Isabel',
  'about_title'    => 'More Than a Plan — <em>A Partnership</em>',
  'about_p1'       => "I started my Medicare journey as a solo agent with a single carrier and a big desire to help my community. I've grown a lot since then — and so has my team.",
  'about_p2'       => "Today, I work with multiple top carriers so we can find the plan that truly fits your needs and budget. We are not affiliated with or endorsed by the federal Medicare program.",
  'about_p3'       => "My service doesn't stop when you enroll. I'm here year-round to help with your questions, your bills, and your benefits — because you deserve more than just a signature.",
  'about_badge_l'  => 'Licensed &amp; Certified',
  'about_badge_v'  => 'Multiple Carriers',
  'hl1' => '🌐 Bilingual English/Spanish',
  'hl2' => '🏥 Multi-Carrier Options',
  'hl3' => '📅 Year-Round Support',
  'hl4' => '✓ No-Cost Agent Services',
  // ── SERVICES ──
  'svc_label'  => 'What We Do',
  'svc_title'  => 'Services <em>Beyond the Plan</em>',
  'svc_sub'    => "We don't just enroll you and disappear. Here's how we show up for our clients every day.",
  's1_title'   => 'Medicare Plan Enrollment',
  's1_body'    => 'We compare Medicare Advantage and Supplement plans across multiple carriers to find the right fit. Plan availability varies by location. Enrollment is subject to Medicare enrollment periods.',
  's2_title'   => 'Bill Explanation &amp; Assistance',
  's2_body'    => 'Confused by an EOB or a surprise charge? We help you understand your Medicare bills and work with providers on your behalf.',
  's3_title'   => 'Transportation Coordination',
  's3_body'    => 'Need a ride to a medical appointment? We help you access transportation benefits that may be included in your plan.',
  's4_title'   => 'Doctor Calls &amp; Advocacy',
  's4_body'    => "We call your doctors' offices on your behalf to confirm coverage, resolve issues, and make sure you're getting the care you're entitled to.",
  's5_title'   => 'Dental Referrals',
  's5_body'    => "We help connect you with dental providers that accept your Medicare plan's dental benefits, where available.",
  's6_title'   => 'Pharmacy Assistance',
  's6_body'    => 'We help you understand your drug coverage, find lower-cost alternatives, and navigate your pharmacy benefits.',
  // ── TESTIMONIALS ──
  'test_label' => 'Client Reviews',
  'test_title' => 'What Our <em>Clients Say</em>',
  'test_sub'   => 'Real experiences from real Medicare beneficiaries we have been honored to serve.',
  't1_body'    => "Isabel took the time to explain everything in Spanish. I finally understood my plan and felt confident about my choices.",
  't2_body'    => 'I had been with the wrong plan for two years before Isabel reviewed my coverage. She found me a better option and saved me money.',
  't3_body'    => "Isabel has been available every time I had a question. She truly cares about her clients.",
  't4_body'    => "She walked my mother through every option in Spanish and never rushed us. We felt cared for, not sold to.",
  't5_body'    => "When my pharmacy changed my copay, Isabel sorted it out with one phone call. I didn't have to do anything.",
  't6_body'    => "Year after year she reviews my plan to make sure it still fits. That kind of follow-up is rare.",
  // ── GALLERY ──
  'gallery_label' => 'Real Moments',
  'gallery_title' => 'Faces &amp; <em>Stories</em>',
  'gallery_sub'   => 'A look at the people and moments behind the work — real clients, real community.',
  // ── VIDEO STORIES ──
  'video_label' => 'Client Stories',
  'video_title' => 'Hear It <em>From Them</em>',
  'video_sub'   => 'Short stories from clients about what changed when they finally had someone in their corner.',
  // ── FAQ ──
  'faq_label' => 'Questions',
  'faq_title' => 'Frequently Asked <em>Questions</em>',
  'faq_sub'   => 'Quick answers to what people ask most. Have another question? Just call or send a message.',
  'faq_q1' => 'How much do your services cost?',
  'faq_a1' => 'Nothing. There is no cost to you for my help — licensed agents are compensated by the insurance carriers, not by you. Your premium is the same whether you use an agent or not.',
  'faq_q2' => 'Do you check my doctors and medications?',
  'faq_a2' => "Yes. Before we choose a plan, I confirm your doctors are in-network and your prescriptions are covered, so there are no surprises later.",
  'faq_q3' => 'When can I enroll or change my plan?',
  'faq_a3' => 'Most changes happen during the Annual Enrollment Period (Oct 15 – Dec 7), but you may qualify for a Special Enrollment Period. I\'ll tell you exactly what applies to your situation.',
  'faq_q4' => 'Can you help me in Spanish?',
  'faq_a4' => "Of course — I help you in English or Spanish, whichever you're most comfortable with.",
  'faq_q5' => 'What happens after I enroll?',
  'faq_a5' => "I'm here year-round. Call me about a bill, a denied claim, a pharmacy issue, or a ride to the doctor — I don't disappear after you sign up.",
  // ── CONTACT ──
  'contact_label' => 'Get in Touch',
  'contact_title' => "Let's Find Your <em>Perfect Plan</em>",
  'contact_sub'   => "Ready to get started? Give us a call, send a message, or fill out the form. We're happy to help — at no cost to you.",
  'method_call'   => 'Call or Text',
  'method_email'  => 'Email',
  'method_web'    => 'Website',
  'form_title'    => 'Send Us a Message',
  'form_fname'    => 'First Name',
  'form_lname'    => 'Last Name',
  'form_phone'    => 'Phone Number',
  'form_email'    => 'Email',
  'form_interest' => "I'm interested in",
  'form_msg'      => 'Message (optional)',
  'form_btn'      => 'Send Message →',
  'form_sent'     => '✅ Message sent! We\'ll be in touch soon.',
  'form_err'      => '⚠️ There was a problem sending your message. Please call us directly.',
  'ph_fname'      => 'Maria',
  'ph_lname'      => 'Garcia',
  'ph_msg'        => 'Tell us a little about what you need help with.',
  'opt1'          => 'Enrolling in Medicare for the first time',
  'opt2'          => 'Switching my current Medicare plan (subject to enrollment periods)',
  'opt3'          => 'Understanding my benefits',
  'opt4'          => 'Help with a bill or pharmacy issue',
  'opt5'          => 'Other',
  // ── CMS REQUIRED DISCLOSURES ─────────────────────────────────────────────
  // CMS Marketing Guidelines require ALL THREE of the following on every page:
  // 1. Not affiliated / not endorsed by U.S. Government
  // 2. "We do not offer every plan" limitation
  // 3. Reference to Medicare.gov / 1-800-MEDICARE / SHIP
  'footer_lic'  => 'Licensed Insurance Agent · Southern California · NPN: [⚠️ INSERT YOUR NPN HERE]',
  'footer_disc' => 'We are not connected with or endorsed by the U.S. Government or the federal Medicare program. We do not offer every plan available in your area. Any information we provide is limited to those plans we do offer in your area. Please contact Medicare.gov, 1-800-MEDICARE (1-800-633-4227), or your local State Health Insurance Assistance Program (SHIP) to get information on all of your options. TTY users: 1-877-486-2048. Our agent services are provided at no cost to you.',
  // CMS: This notice bar MUST appear prominently on every page. (CSS-only previously — now rendered in HTML.)
  'cms_notice'  => '⚠️ We do not offer every plan available in your area. Please contact <a href="https://www.medicare.gov" target="_blank" rel="noopener">Medicare.gov</a> or call <strong>1-800-MEDICARE (1-800-633-4227)</strong> to compare all available options. TTY: 1-877-486-2048.',
],

'es' => [
  'page_title'     => 'Medicare con Isabel | Isabel Fuentes',
  'nav_about'      => 'Sobre Mí',
  'nav_services'   => 'Servicios',
  'nav_reviews'    => 'Opiniones',
  'nav_contact'    => 'Contacto',
  'hero_h1'        => 'Medicare Fácil<br><em>y Muy Personal</em>',
  'hero_sub'       => 'Hola, soy Isabel Fuentes. Te ayudo a encontrar el plan de Medicare ideal — en español o inglés — sin ningún costo para ti por mis servicios.',
  'hero_cta1'      => '📞 Llámanos Hoy',
  'hero_cta2'      => 'Ver Cómo Ayudamos →',
  'hero_cta3'      => '📋 Obtén una Cotización',
  'hero_card_sub'  => 'Agente de Seguros Medicare · Sur de California',
  'stat1'          => 'Años Ayudando',
  'stat2'          => 'Aseguradoras',
  'stat3'          => 'Sin Costo para Ti',
  'stat4'          => 'Hablamos Español',
  'carriers_section_label' => 'Aseguradoras con las que Trabajamos',
  'carriers_section_sub'   => 'Trabajamos con estas aseguradoras líderes para encontrarte el mejor plan — <strong>no representamos todos los planes disponibles en tu área</strong>.',
  'about_label'    => 'Sobre Isabel',
  'about_title'    => 'Más que un Plan — <em>Una Alianza</em>',
  'about_p1'       => 'Comencé mi trayectoria en Medicare como agente independiente con una sola aseguradora y un gran deseo de ayudar a mi comunidad. He crecido mucho desde entonces — y mi equipo también.',
  'about_p2'       => 'Hoy trabajo con múltiples aseguradoras para encontrar el plan que realmente se adapte a tus necesidades y presupuesto. No estamos afiliados ni respaldados por el programa federal de Medicare.',
  'about_p3'       => 'Mi servicio no termina cuando te inscribes. Estoy disponible todo el año para ayudarte con tus preguntas, tus facturas y tus beneficios — porque mereces más que solo una firma.',
  'about_badge_l'  => 'Licenciada y Certificada',
  'about_badge_v'  => 'Múltiples Aseguradoras',
  'hl1' => '🌐 Bilingüe Español/Inglés',
  'hl2' => '🏥 Múltiples Aseguradoras',
  'hl3' => '📅 Apoyo Todo el Año',
  'hl4' => '✓ Servicio Sin Costo',
  'svc_label'  => 'Lo que Hacemos',
  'svc_title'  => 'Servicios <em>Más Allá del Plan</em>',
  'svc_sub'    => 'No solo te inscribimos y desaparecemos. Así es como estamos presentes para nuestros clientes cada día.',
  's1_title'   => 'Inscripción en Medicare',
  's1_body'    => 'Comparamos planes de Medicare Advantage y Suplemento entre múltiples aseguradoras para encontrar el más adecuado. La disponibilidad varía según la ubicación. La inscripción está sujeta a los períodos de inscripción de Medicare.',
  's2_title'   => 'Explicación de Facturas',
  's2_body'    => '¿Confundido con un estado de cuenta? Revisamos contigo tus facturas de Medicare y trabajamos con los proveedores en tu nombre.',
  's3_title'   => 'Coordinación de Transporte',
  's3_body'    => '¿Necesitas transporte para tu cita médica? Te ayudamos a acceder a los beneficios de transporte que pueden estar incluidos en tu plan.',
  's4_title'   => 'Llamadas al Médico',
  's4_body'    => 'Llamamos a los consultorios de tus médicos en tu nombre para confirmar cobertura, resolver problemas y asegurarnos de que recibas la atención a la que tienes derecho.',
  's5_title'   => 'Referencias Dentales',
  's5_body'    => 'Te conectamos con proveedores dentales que aceptan los beneficios dentales de tu plan de Medicare, cuando estén disponibles.',
  's6_title'   => 'Asistencia en Farmacia',
  's6_body'    => 'Te ayudamos a entender tu cobertura de medicamentos, encontrar alternativas de menor costo y aprovechar tus beneficios de farmacia.',
  'test_label' => 'Opiniones de Clientes',
  'test_title' => 'Lo que Dicen <em>Nuestros Clientes</em>',
  'test_sub'   => 'Experiencias reales de beneficiarios de Medicare a quienes hemos tenido el honor de servir.',
  't1_body'    => 'Isabel se tomó el tiempo de explicarme todo en español. Por fin entendí mi plan y me sentí seguro con mis decisiones.',
  't2_body'    => 'Estuve con el plan equivocado dos años hasta que Isabel revisó mi cobertura. Me encontró una mejor opción y me ahorró dinero.',
  't3_body'    => 'Isabel siempre ha estado disponible cada vez que tuve una pregunta. Realmente se preocupa por sus clientes.',
  't4_body'    => 'Le explicó cada opción a mi mamá en español y nunca nos apuró. Nos sentimos cuidados, no presionados.',
  't5_body'    => 'Cuando mi farmacia cambió mi copago, Isabel lo resolvió con una sola llamada. No tuve que hacer nada.',
  't6_body'    => 'Año tras año revisa mi plan para asegurarse de que siga siendo el adecuado. Ese seguimiento es raro.',
  // ── GALLERY ──
  'gallery_label' => 'Momentos Reales',
  'gallery_title' => 'Rostros e <em>Historias</em>',
  'gallery_sub'   => 'Un vistazo a las personas y momentos detrás del trabajo — clientes reales, comunidad real.',
  // ── VIDEO STORIES ──
  'video_label' => 'Historias de Clientes',
  'video_title' => 'Escúchalo <em>de Ellos</em>',
  'video_sub'   => 'Breves historias de clientes sobre lo que cambió cuando por fin tuvieron a alguien de su lado.',
  // ── FAQ ──
  'faq_label' => 'Preguntas',
  'faq_title' => 'Preguntas <em>Frecuentes</em>',
  'faq_sub'   => '¿Tienes otra pregunta? Solo llama o envía un mensaje.',
  'faq_q1' => '¿Cuánto cuestan tus servicios?',
  'faq_a1' => 'Nada. No hay costo para ti por mi ayuda — a los agentes licenciados nos pagan las aseguradoras, no tú. Tu prima es la misma uses o no un agente.',
  'faq_q2' => '¿Revisas mis médicos y medicamentos?',
  'faq_a2' => 'Sí. Antes de elegir un plan, confirmo que tus médicos estén en la red y que tus medicamentos estén cubiertos, para que no haya sorpresas después.',
  'faq_q3' => '¿Cuándo puedo inscribirme o cambiar mi plan?',
  'faq_a3' => 'La mayoría de los cambios ocurren durante el Período de Inscripción Anual (15 oct – 7 dic), pero podrías calificar para un Período de Inscripción Especial. Te diré exactamente qué aplica a tu situación.',
  'faq_q4' => '¿Puedes ayudarme en español?',
  'faq_a4' => 'Claro — te ayudo en español o inglés, como te sientas más cómodo.',
  'faq_q5' => '¿Qué pasa después de inscribirme?',
  'faq_a5' => 'Estoy disponible todo el año. Llámame por una factura, un reclamo negado, un problema de farmacia o transporte al médico — no desaparezco después de que te inscribes.',
  'contact_label' => 'Contáctanos',
  'contact_title' => 'Encontremos Tu <em>Plan Ideal</em>',
  'contact_sub'   => 'Llámanos, envíanos un mensaje o llena el formulario. Estamos felices de ayudarte — sin ningún costo para ti.',
  'method_call'   => 'Llama o Envía SMS',
  'method_email'  => 'Correo Electrónico',
  'method_web'    => 'Sitio Web',
  'form_title'    => 'Envíanos un Mensaje',
  'form_fname'    => 'Nombre',
  'form_lname'    => 'Apellido',
  'form_phone'    => 'Número de Teléfono',
  'form_email'    => 'Correo Electrónico',
  'form_interest' => 'Me interesa',
  'form_msg'      => 'Mensaje (opcional)',
  'form_btn'      => 'Enviar Mensaje →',
  'form_sent'     => '✅ ¡Mensaje enviado! Nos pondremos en contacto pronto.',
  'form_err'      => '⚠️ Hubo un problema al enviar tu mensaje. Por favor llámanos directamente.',
  'ph_fname'      => 'María',
  'ph_lname'      => 'García',
  'ph_msg'        => 'Cuéntanos un poco sobre en qué necesitas ayuda.',
  'opt1'          => 'Inscribirme en Medicare por primera vez',
  'opt2'          => 'Cambiar mi plan actual de Medicare (sujeto a períodos de inscripción)',
  'opt3'          => 'Entender mis beneficios',
  'opt4'          => 'Ayuda con una factura o farmacia',
  'opt5'          => 'Otro',
  'footer_lic'  => 'Agente de Seguros Licenciada · Sur de California · NPN: [⚠️ INGRESA TU NPN AQUÍ]',
  'footer_disc' => 'No estamos afiliados ni respaldados por el gobierno de los EE. UU. ni por el programa federal de Medicare. No ofrecemos todos los planes disponibles en su área. Cualquier información que proporcionamos se limita a los planes que ofrecemos en su área. Comuníquese con Medicare.gov, llame al 1-800-MEDICARE (1-800-633-4227) o contacte a su Programa Estatal de Asistencia de Seguro de Salud (SHIP) local para obtener información sobre todas sus opciones. Usuarios de TTY: 1-877-486-2048. Nuestros servicios de agente se prestan sin costo para usted.',
  'cms_notice'  => '⚠️ No ofrecemos todos los planes disponibles en su área. Comuníquese con <a href="https://www.medicare.gov" target="_blank" rel="noopener">Medicare.gov</a> o llame al <strong>1-800-MEDICARE (1-800-633-4227)</strong> para comparar todas las opciones disponibles. TTY: 1-877-486-2048.',
],
];
$t = $T[$lang];

// ── MAIL CONFIGURATION ────────────────────────────────────────────────────────
// ⚠️  SETUP INSTRUCTIONS:
//   1. Set MAIL_TO to the inbox where you want leads delivered.
//   2. Set MAIL_FROM to an address on your domain (e.g. noreply@withisabelfuentes.com).
//   3. PHP's mail() works on Bluehost out of the box — no SMTP credentials needed.
//   4. OPTIONAL: Fill in SMTP credentials below to send via SMTP instead.
//      Leave SMTP_PASS as 'YOUR_EMAIL_PASSWORD_HERE' to keep using mail().
// ─────────────────────────────────────────────────────────────────────────────
// ⚠️  QUOTING LINK — your agent-branded self-quote page.
//    Most agents use Medicareful, MyMedicareBot, or Sunfire: the client lands on a
//    page branded to YOU, enters their ZIP / doctors / meds, and you receive the lead.
//    Paste that personal URL here. Examples:
//      'https://isabelfuentes.medicareful.com'
//      'https://your-name.mymedicarebot.com'
//    Until you set this, the "Get a Quote" buttons safely scroll to the contact form.
define('QUOTE_URL', 'YOUR_AGENT_QUOTE_URL_HERE');

// ── SECRETS (kept OUT of git) ─────────────────────────────────────────────────
// Create a file named `mail-secret.php` next to this page on the server, with:
//
//     <?php return [
//       'smtp_pass' => 'your-real-mailbox-password',
//       // optional overrides:
//       // 'mail_to'   => 'Connect@withisabelfuentes.com',
//       // 'mail_from' => 'mail@withisabelfuentes.com',
//     ];
//
// That file is git-ignored, so the password never enters the repo and is NOT
// overwritten by deploys. When it's present, the form sends via authenticated
// SMTP (lands in the inbox). When it's absent, the placeholder keeps PHP mail().
$secret = [];
$secretFile = __DIR__ . '/mail-secret.php';
if (is_file($secretFile)) {
    $loaded = include $secretFile;
    if (is_array($loaded)) $secret = $loaded;
}

define('MAIL_TO',   $secret['mail_to']   ?? 'Connect@withisabelfuentes.com');
define('MAIL_FROM', $secret['mail_from'] ?? 'noreply@withisabelfuentes.com');
define('SMTP_HOST', $secret['smtp_host'] ?? 'mail.withisabelfuentes.com');
define('SMTP_PORT', $secret['smtp_port'] ?? 587);
define('SMTP_USER', $secret['smtp_user'] ?? 'mail@withisabelfuentes.com');
define('SMTP_PASS', $secret['smtp_pass'] ?? 'YOUR_EMAIL_PASSWORD_HERE'); // set in mail-secret.php

// Quote buttons point to your self-quote page once configured; otherwise to the form.
$quoteIsExternal = (QUOTE_URL !== 'YOUR_AGENT_QUOTE_URL_HERE');
$quoteHref       = $quoteIsExternal ? QUOTE_URL : '#contact';
$quoteAttrs      = $quoteIsExternal ? ' target="_blank" rel="noopener noreferrer"' : '';

/**
 * Primary mailer. Tries SMTP first (if configured), then falls back to PHP mail().
 * BUG FIX from previous version: SMTP auth failure now correctly falls back to mail()
 * instead of returning false silently.
 */
function sendMail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    // Try SMTP only when a real password has been entered
    if (SMTP_PASS !== 'YOUR_EMAIL_PASSWORD_HERE') {
        if (sendSmtpMail($to, $subject, $body, $replyTo)) {
            return true;
        }
        // If SMTP failed for any reason, fall through to mail()
    }

    // Native PHP mail() — reliable on Bluehost shared hosting
    $from    = MAIL_FROM;
    $reply   = filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : $from;
    $headers = implode("\r\n", [
        "From: Medicare with Isabel <{$from}>",
        "Reply-To: {$reply}",
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "X-Mailer: PHP/" . phpversion(),
    ]);
    // The 5th arg sets the envelope sender (-f). Without it, shared hosts use a
    // server default that fails SPF and gets the mail flagged as spam / dropped.
    return (bool) @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $from);
}

/**
 * Appends a lead to a local backup file so no submission is ever lost — even if
 * email delivery fails. The file starts with `<?php exit;` so it can't be read
 * over the web (PHP runs the exit and returns nothing). We read past that line.
 */
function logLead(string $outcome, array $data): void {
    $file = __DIR__ . '/leads-log.php';
    if (!file_exists($file)) {
        @file_put_contents($file, "<?php exit; /* Lead backup — do not delete. Not web-readable. */ ?>\n");
    }
    $line = sprintf(
        "[%s] %-12s | %s %s | tel:%s | %s | email:%s | lang:%s | ip:%s",
        date('Y-m-d H:i:s'), $outcome,
        $data['fname'] ?? '', $data['lname'] ?? '', $data['phone'] ?? '',
        $data['interest'] ?? '', $data['email'] ?? '-', $data['lang'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '-'
    );
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function sendSmtpMail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    $from = MAIL_FROM; $host = SMTP_HOST; $port = SMTP_PORT;
    $user = SMTP_USER; $pass = SMTP_PASS;
    // The envelope sender must be an address the SMTP server is authorized to send
    // as — that's the authenticated user, not necessarily the display "From".
    $envelope = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : $from;
    $errno = 0; $errstr = '';
    $prefix = ($port == 465) ? 'ssl://' : '';
    $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $read = function() use ($sock) { return fgets($sock, 515); };
    $send = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

    $read();
    $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    while ($line = $read()) { if ($line[3] === ' ') break; }

    if ($port == 587) {
        $send("STARTTLS"); $read();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        while ($line = $read()) { if ($line[3] === ' ') break; }
    }

    $send("AUTH LOGIN"); $read();
    $send(base64_encode($user)); $read();
    $send(base64_encode($pass)); $authResp = $read();

    if (strpos($authResp, '235') === false) {
        fclose($sock);
        return false; // caller will fall back to mail()
    }

    $reply   = filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : $from;
    $headers = "From: Medicare with Isabel <{$from}>\r\nReply-To: {$reply}\r\nContent-Type: text/plain; charset=UTF-8";
    $send("MAIL FROM:<{$envelope}>"); $read();
    $send("RCPT TO:<{$to}>"); $read();
    $send("DATA"); $read();
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $send("To: {$to}\r\nFrom: Medicare with Isabel <{$from}>\r\nSubject: {$encodedSubject}\r\n{$headers}\r\n\r\n{$body}\r\n.");
    $dataResp = $read();
    $send("QUIT"); fclose($sock);
    return strpos($dataResp, '250') !== false;
}

// ── CSRF TOKEN ────────────────────────────────────────────────────────────────
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── FORM HANDLER ──────────────────────────────────────────────────────────────
$formMessage = ''; $formSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submit'])) {
    // CSRF check
    $csrfValid = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    // Honeypot check (bots fill hidden fields, humans don't)
    $isBot = !empty($_POST['website_url']);

    if (!$csrfValid || $isBot) {
        // Silently reject to the visitor — but log it so a stale-token / spam issue
        // is visible instead of leads vanishing without a trace.
        logLead($isBot ? 'BOT' : 'CSRF_FAIL', [
            'fname' => $_POST['fname'] ?? '', 'phone' => $_POST['phone'] ?? '', 'lang' => $lang,
        ]);
        $formMessage = ''; $formSuccess = false;
    } else {
        $fname    = htmlspecialchars(strip_tags(trim($_POST['fname']    ?? '')));
        $lname    = htmlspecialchars(strip_tags(trim($_POST['lname']    ?? '')));
        $phone    = htmlspecialchars(strip_tags(trim($_POST['phone']    ?? '')));
        $email    = htmlspecialchars(strip_tags(trim($_POST['email']    ?? '')));
        $interest = htmlspecialchars(strip_tags(trim($_POST['interest'] ?? '')));
        $message  = htmlspecialchars(strip_tags(trim($_POST['message']  ?? '')));
        $fLang    = in_array($_POST['form_lang'] ?? '', ['en','es']) ? $_POST['form_lang'] : 'en';
        $replyTo  = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

        $logData = ['fname'=>$fname,'lname'=>$lname,'phone'=>$phone,'email'=>$email,'interest'=>$interest,'lang'=>$fLang];

        if ($fname && $phone) {
            $subject = "Nueva consulta Medicare – {$fname} {$lname}";
            $body    = "============================\n"
                     . "NUEVA CONSULTA — Medicare with Isabel\n"
                     . "============================\n"
                     . "Nombre:   {$fname} {$lname}\n"
                     . "Teléfono: {$phone}\n"
                     . "Email:    " . ($email ?: '—') . "\n"
                     . "Interés:  {$interest}\n"
                     . "Idioma:   " . strtoupper($fLang) . "\n"
                     . "Mensaje:\n{$message}\n"
                     . "============================\n"
                     . "Enviado desde withisabelfuentes.com";
            $formSuccess = sendMail(MAIL_TO, $subject, $body, $replyTo);
            // Always log the lead — so even if email delivery fails, it's captured.
            logLead($formSuccess ? 'SENT' : 'MAIL_FAILED', $logData);
            $formMessage = $formSuccess ? $t['form_sent'] : $t['form_err'];
        } else {
            logLead('INCOMPLETE', $logData);
            $formMessage = $lang === 'es'
                ? '⚠️ Por favor ingresa tu nombre y teléfono.'
                : '⚠️ Please enter your name and phone number.';
        }
    }
}

// ── LANGUAGE SWITCHER ─────────────────────────────────────────────────────────
function langUrl(string $l): string { return '?setlang=' . $l; }
if (isset($_GET['setlang']) && in_array($_GET['setlang'], ['en','es'])) {
    setcookie('pLang', $_GET['setlang'], time() + (86400 * 365), '/');
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= $t['page_title'] ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;600&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sky:#d6eaf8;--sky-mid:#a9cce3;--sky-deep:#5b9dc9;--sky-dark:#2471a3;
  --off-white:#f7fbff;--text:#1a2a3a;--text-soft:#4a6077;
  --radius:20px;--shadow:0 4px 24px rgba(36,113,163,.1);--shadow-hover:0 12px 40px rgba(36,113,163,.18)
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--off-white);color:var(--text);overflow-x:hidden}

/* ── STICKY HEADER WRAPPER (CMS bar + Nav together) ──
   Both elements live inside this wrapper so they stick as one unit.
   This avoids the z-index/top race condition of fixed + static elements. */
.sticky-header{position:sticky;top:0;z-index:200;width:100%}

/* ── CMS NOTICE BAR ── */
.cms-notice-bar{background:#1a3d5c;border-bottom:2px solid var(--sky-deep);padding:.65rem 5%;text-align:center}
.cms-notice-bar p{font-size:.78rem;color:rgba(255,255,255,.88);line-height:1.6;max-width:960px;margin:0 auto}
.cms-notice-bar a{color:#7ec8e3;font-weight:600;text-decoration:none}
.cms-notice-bar a:hover{text-decoration:underline}

/* ── NAV ── now in normal flow inside .sticky-header, no more fixed positioning */
nav{position:static;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-bottom:1px solid rgba(169,204,227,.3);padding:.75rem 5%;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(36,113,163,.08)}
.nav-logo{display:flex;align-items:center;text-decoration:none}
.nav-logo-img{height:72px;width:auto;display:block}
.nav-right{display:flex;align-items:center;gap:1.5rem}
.nav-links{display:flex;gap:2rem;list-style:none}
.nav-links a{font-size:1rem;font-weight:600;letter-spacing:.04em;color:var(--text-soft);text-decoration:none;transition:color .2s}
.nav-links a:hover{color:var(--sky-dark)}
.nav-cta{background:var(--sky-dark);color:white!important;padding:.6rem 1.5rem;border-radius:50px}
.lang-toggle{display:flex;background:var(--sky);border-radius:50px;border:1.5px solid var(--sky-mid);overflow:hidden}
.lang-btn{padding:.35rem .85rem;font-size:.75rem;font-weight:700;text-decoration:none;color:var(--sky-dark);transition:all .2s}
.lang-btn.active{background:var(--sky-dark);color:white;border-radius:50px}

/* ── HERO ── */
#hero{min-height:calc(100vh - 100px);display:flex;align-items:center;padding:60px 5% 60px;background:radial-gradient(ellipse 80% 60% at 70% 40%,rgba(169,204,227,.18) 0%,transparent 70%);position:relative;overflow:hidden}
.hero-blob{position:absolute;border-radius:50%;filter:blur(60px);opacity:.25;pointer-events:none}
.blob-1{width:500px;height:500px;background:var(--sky-mid);top:-100px;right:-100px}
.blob-2{width:300px;height:300px;background:var(--sky-deep);bottom:50px;left:-80px}
.hero-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:center;width:100%}
.hero-text{position:relative;z-index:2}
.hero-logo-big{width:320px;max-width:90%;height:auto;display:block;margin-bottom:.5rem;filter:drop-shadow(0 4px 16px rgba(36,113,163,.15))}
.hero-location{font-size:.9rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--sky-dark);margin-bottom:1rem}
h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2.8rem,5vw,4.2rem);font-weight:600;line-height:1.12;margin-bottom:1.25rem;color:var(--text)}
h1 em{font-style:italic;color:var(--sky-dark)}
.hero-sub{font-size:1.05rem;line-height:1.75;color:var(--text-soft);max-width:480px;margin-bottom:2rem;font-weight:300}
.hero-actions{display:flex;gap:1rem;flex-wrap:wrap}
.btn-primary{background:var(--sky-dark);color:white;padding:.9rem 2rem;border-radius:50px;text-decoration:none;font-weight:600;font-size:.95rem;transition:all .25s;display:inline-flex;align-items:center;gap:.5rem}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(36,113,163,.4)}
.btn-secondary{border:2px solid var(--sky-mid);color:var(--sky-dark);padding:.9rem 2rem;border-radius:50px;text-decoration:none;font-weight:600;font-size:.95rem;transition:all .25s}
.btn-secondary:hover{background:var(--sky);border-color:var(--sky-deep)}
.hero-photo-wrap{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center}
.hero-isabel{width:100%;max-width:420px;height:auto;object-fit:contain;object-position:top center;filter:drop-shadow(0 8px 40px rgba(36,113,163,.15))}
@keyframes floatCard{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.hero-stats-float{background:white;border-radius:24px;padding:1.5rem;box-shadow:var(--shadow-hover);width:100%;max-width:480px;animation:floatCard 4s ease-in-out infinite}
.hero-stats{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.stat{background:var(--off-white);border-radius:14px;padding:1rem;text-align:center}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:2rem;font-weight:600;color:var(--sky-dark)}
.stat-label{font-size:.72rem;color:var(--text-soft);text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}

/* ── CARRIERS SECTION (redesigned) ── */
#carriers{background:var(--text);padding:52px 5%;overflow:hidden}
.carriers-inner{max-width:1200px;margin:0 auto;text-align:center}
.carriers-heading{font-size:.72rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.45);margin-bottom:.6rem}
.carriers-sub{font-size:.83rem;color:rgba(255,255,255,.55);margin-bottom:2.5rem;line-height:1.6}
.carriers-sub strong{color:rgba(255,255,255,.75)}
/* Marquee track — duplicated list scrolls left infinitely */
.carriers-track-wrap{position:relative;overflow:hidden}
.carriers-track-wrap::before,
.carriers-track-wrap::after{content:'';position:absolute;top:0;bottom:0;width:80px;z-index:2;pointer-events:none}
.carriers-track-wrap::before{left:0;background:linear-gradient(to right,var(--text),transparent)}
.carriers-track-wrap::after{right:0;background:linear-gradient(to left,var(--text),transparent)}
@keyframes marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.carriers-track{display:flex;gap:1.25rem;width:max-content;animation:marquee 22s linear infinite}
.carriers-track:hover{animation-play-state:paused}
.carrier-card{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:.55rem;padding:1.1rem 1.6rem;border-radius:16px;min-width:140px;
  border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);
  transition:all .25s;cursor:default;flex-shrink:0
}
.carrier-card:hover{background:rgba(255,255,255,.1);border-color:rgba(169,204,227,.35);transform:translateY(-3px)}
.carrier-dot{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem;color:white;letter-spacing:-.02em;flex-shrink:0}
.carrier-name-text{font-size:.8rem;font-weight:600;color:rgba(255,255,255,.8);white-space:nowrap}
/* Each carrier gets a distinct accent color */
.cc-scan    .carrier-dot{background:linear-gradient(135deg,#007DB5,#0099cc)}
.cc-anthem  .carrier-dot{background:linear-gradient(135deg,#1A5276,#2471a3)}
.cc-humana  .carrier-dot{background:linear-gradient(135deg,#00834F,#00a85f)}
.cc-align   .carrier-dot{background:linear-gradient(135deg,#d35400,#e67e22)}
.cc-lacare  .carrier-dot{background:linear-gradient(135deg,#003B71,#1a5276)}
.cc-hnet    .carrier-dot{background:linear-gradient(135deg,#009B4E,#00c462)}
.cc-molina  .carrier-dot{background:linear-gradient(135deg,#003087,#0047cc)}
.carriers-cms-note{font-size:.72rem;color:rgba(255,255,255,.38);margin-top:2rem;line-height:1.55}
.carriers-cms-note a{color:rgba(169,204,227,.7);text-decoration:none}
.carriers-cms-note a:hover{color:var(--sky-mid)}

/* ── SECTIONS ── */
section{padding:90px 5%}
.section-inner{max-width:1200px;margin:0 auto}
.section-label{font-size:.75rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--sky-deep);margin-bottom:.75rem}
.section-title{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,3.5vw,3rem);font-weight:600;line-height:1.15;margin-bottom:1rem;color:var(--text)}
.section-title em{font-style:italic;color:var(--sky-dark)}
.section-sub{font-size:1rem;color:var(--text-soft);line-height:1.7;max-width:560px;font-weight:300}
.section-header{margin-bottom:3.5rem}

/* ── ABOUT ── */
#about{background:white}
.about-grid{display:grid;grid-template-columns:1fr 1.1fr;gap:5rem;align-items:center}
.about-img-wrap{position:relative}
.about-img-box{width:100%;aspect-ratio:3/4;max-height:520px;border-radius:24px;background:var(--sky);overflow:hidden}
.about-img-box img{width:100%;height:100%;object-fit:cover;object-position:top center}
.about-float{position:absolute;bottom:-20px;right:-20px;background:white;border-radius:16px;padding:1rem 1.5rem;box-shadow:var(--shadow-hover)}
.about-float p{font-size:.75rem;color:var(--text-soft);margin-bottom:.2rem}
.about-float strong{font-size:1.1rem;color:var(--sky-dark);font-weight:600}
.about-text p{font-size:1rem;line-height:1.85;color:var(--text-soft);margin-bottom:1.25rem;font-weight:300}
.highlights{display:flex;gap:1rem;flex-wrap:wrap;margin-top:2rem}
.highlight{display:flex;align-items:center;gap:.6rem;background:var(--off-white);border-radius:50px;padding:.6rem 1.2rem;font-size:.85rem;font-weight:600;color:var(--text)}

/* ── SERVICES ── */
#services{background:var(--off-white)}
.services-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
.service-card{background:white;border-radius:var(--radius);padding:2rem;border:1px solid rgba(169,204,227,.3);position:relative;overflow:hidden;transition:all .3s}
.service-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--sky-deep),var(--sky-dark));transform:scaleX(0);transition:transform .3s}
.service-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-hover)}
.service-card:hover::before{transform:scaleX(1)}
.svc-icon{width:52px;height:52px;background:var(--sky);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:1rem}
.service-card h3{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:600;margin-bottom:.6rem;color:var(--text)}
.service-card p{font-size:.88rem;color:var(--text-soft);line-height:1.7;font-weight:300}

/* ── TESTIMONIALS ── */
#testimonials{background:radial-gradient(ellipse 60% 80% at 50% 50%,rgba(169,204,227,.2) 0%,transparent 70%),var(--off-white)}
.testimonials-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
.testimonial-card{background:white;border-radius:var(--radius);padding:2rem;border:1px solid rgba(169,204,227,.3);box-shadow:var(--shadow)}
.quote-mark{font-family:'Cormorant Garamond',serif;font-size:4rem;line-height:.8;color:var(--sky-deep);margin-bottom:.75rem}
.testimonial-card p{font-size:.9rem;line-height:1.75;color:var(--text-soft);font-weight:300;margin-bottom:1.5rem}
.t-author{display:flex;align-items:center;gap:.75rem}
.t-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--sky-deep),var(--sky-dark));display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:white}
.t-name{font-size:.88rem;font-weight:600}
.t-city{font-size:.75rem;color:var(--text-soft)}

/* ── CONTACT ── */
#contact{background:white}
.contact-wrap{display:grid;grid-template-columns:1fr 1fr;gap:4rem;align-items:start}
.contact-info>p{font-size:.95rem;color:var(--text-soft);line-height:1.75;font-weight:300;margin-bottom:2rem}
.contact-methods{display:flex;flex-direction:column;gap:1rem}
.c-method{display:flex;align-items:center;gap:1rem;padding:1.1rem 1.4rem;background:var(--off-white);border-radius:16px;text-decoration:none;border:1px solid rgba(169,204,227,.3);transition:all .2s}
.c-method:hover{background:var(--sky);border-color:var(--sky-mid)}
.c-icon{width:42px;height:42px;background:var(--sky-dark);color:white;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.c-label{font-size:.75rem;color:var(--text-soft);text-transform:uppercase;letter-spacing:.06em}
.c-value{font-weight:600;font-size:.95rem;color:var(--sky-dark)}
/* ── FORM ── */
.form-box{background:var(--off-white);border-radius:var(--radius);padding:2.5rem;border:1px solid rgba(169,204,227,.3)}
.form-box h3{font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:600;margin-bottom:1.5rem;color:var(--text)}
.form-inner{display:flex;flex-direction:column;gap:1rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-group{display:flex;flex-direction:column;gap:.4rem}
/* Honeypot — hidden from real users, visible to bots */
.form-honeypot{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;opacity:0;tabindex:-1}
label{font-size:.78rem;font-weight:600;letter-spacing:.05em;color:var(--text-soft);text-transform:uppercase}
input,select,textarea{padding:.85rem 1.1rem;border:1.5px solid rgba(169,204,227,.5);border-radius:12px;font-family:inherit;font-size:.9rem;background:white;color:var(--text);outline:none;transition:border-color .2s}
input:focus,select:focus,textarea:focus{border-color:var(--sky-deep);box-shadow:0 0 0 3px rgba(91,157,201,.15)}
textarea{resize:vertical;min-height:110px}
.form-submit{background:var(--sky-dark);color:white;padding:1rem 2rem;border:none;border-radius:50px;font-family:inherit;font-size:1rem;font-weight:600;cursor:pointer;transition:all .25s;width:100%}
.form-submit:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(36,113,163,.4)}
.form-alert{padding:1rem 1.25rem;border-radius:12px;font-size:.92rem;font-weight:500;margin-bottom:1rem}
.form-alert.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.form-alert.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.form-soa-note{font-size:.72rem;color:var(--text-soft);line-height:1.55;margin-top:.5rem;padding:.75rem;background:rgba(169,204,227,.15);border-radius:10px;border-left:3px solid var(--sky-deep)}

/* ── FOOTER ── */
footer{background:var(--text);color:rgba(255,255,255,.6);padding:2.5rem 5%;text-align:center}
footer strong{color:white}
.footer-inner{max-width:1200px;margin:0 auto}
.footer-logo{margin-bottom:.75rem}
.footer-logo img{height:50px;width:auto;filter:brightness(0) invert(1);opacity:.85}
.footer-links{display:flex;justify-content:center;gap:2rem;margin-bottom:1rem;flex-wrap:wrap}
.footer-links a{color:rgba(255,255,255,.5);text-decoration:none;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;transition:color .2s}
.footer-links a:hover{color:white}
.footer-disc{font-size:.72rem;line-height:1.65;color:rgba(255,255,255,.45);max-width:820px;margin:.75rem auto 0;border-top:1px solid rgba(255,255,255,.1);padding-top:.75rem}

/* ── ANIMATIONS ── */
.fade-in{opacity:0;transform:translateY(24px);animation:fadeIn .7s ease forwards}
@keyframes fadeIn{to{opacity:1;transform:translateY(0)}}
.d1{animation-delay:.1s}.d2{animation-delay:.2s}.d3{animation-delay:.3s}.d4{animation-delay:.4s}
/* Reveal-on-scroll (added via JS IntersectionObserver) */
.reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
.reveal.in{opacity:1;transform:none}

/* ── TESTIMONIAL SLIDER ── */
.t-slider{position:relative;max-width:920px;margin:0 auto;overflow:hidden}
.t-track{display:flex;transition:transform .5s cubic-bezier(.4,0,.2,1)}
.t-slide{min-width:100%;padding:.5rem;flex-shrink:0}
@media(min-width:760px){.t-slide{min-width:50%}}
.t-slider .testimonial-card{height:100%}
.t-controls{display:flex;align-items:center;justify-content:center;gap:1.25rem;margin-top:2.25rem}
.t-arrow{width:46px;height:46px;border-radius:50%;border:1.5px solid var(--sky-mid);background:white;color:var(--sky-dark);font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.t-arrow:hover{background:var(--sky-dark);color:white;border-color:var(--sky-dark);transform:translateY(-2px)}
.t-dots{display:flex;gap:.5rem}
.t-dot{width:9px;height:9px;border-radius:50%;background:var(--sky-mid);border:none;cursor:pointer;padding:0;transition:all .25s}
.t-dot.active{background:var(--sky-dark);width:26px;border-radius:5px}

/* ── PHOTO GALLERY ── */
#gallery{background:white}
.gallery-grid{display:grid;grid-template-columns:repeat(4,1fr);grid-auto-rows:180px;gap:1rem}
.gallery-item{position:relative;border-radius:16px;overflow:hidden;cursor:pointer;border:1px solid rgba(169,204,227,.3)}
.gallery-item.wide{grid-column:span 2}
.gallery-item.tall{grid-row:span 2}
.gallery-item img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease;display:block}
.gallery-item:hover img{transform:scale(1.06)}
.gallery-item .g-cap{position:absolute;left:0;right:0;bottom:0;padding:1rem;font-size:.8rem;font-weight:600;color:white;background:linear-gradient(to top,rgba(26,42,58,.85),transparent);opacity:0;transition:opacity .3s}
.gallery-item:hover .g-cap{opacity:1}
.gallery-ph{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem;width:100%;height:100%;color:var(--sky-dark);font-size:.76rem;font-weight:600;text-align:center;padding:1rem;background:repeating-linear-gradient(45deg,rgba(169,204,227,.12),rgba(169,204,227,.12) 12px,rgba(169,204,227,.26) 12px,rgba(169,204,227,.26) 24px)}
.gallery-ph span{font-size:1.5rem;opacity:.6}
/* Lightbox */
.lightbox{position:fixed;inset:0;background:rgba(15,28,40,.92);display:none;align-items:center;justify-content:center;z-index:500;padding:5%}
.lightbox.open{display:flex}
.lightbox img{max-width:100%;max-height:90vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.lightbox-close{position:absolute;top:18px;right:28px;font-size:2.2rem;line-height:1;color:white;cursor:pointer;background:none;border:none}

/* ── VIDEO STORIES ── */
#stories{background:var(--off-white)}
.video-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
.video-card{background:white;border-radius:var(--radius);overflow:hidden;border:1px solid rgba(169,204,227,.3);box-shadow:var(--shadow);transition:all .3s}
.video-card:hover{transform:translateY(-5px);box-shadow:var(--shadow-hover)}
.video-frame{position:relative;aspect-ratio:16/9;background:var(--text);cursor:pointer;overflow:hidden}
.video-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.video-frame img{width:100%;height:100%;object-fit:cover;display:block}
.video-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
.video-play::after{content:'▶';color:white;font-size:1.3rem;width:62px;height:62px;border-radius:50%;background:rgba(36,113,163,.9);display:flex;align-items:center;justify-content:center;padding-left:4px;transition:transform .25s}
.video-frame:hover .video-play::after{transform:scale(1.12)}
.video-ph{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem;width:100%;height:100%;color:rgba(255,255,255,.7);font-size:.78rem;text-align:center;padding:1rem}
.video-meta{padding:1.1rem 1.3rem}
.video-meta h4{font-family:'Cormorant Garamond',serif;font-size:1.18rem;font-weight:600;color:var(--text);margin-bottom:.15rem}
.video-meta p{font-size:.78rem;color:var(--text-soft)}

/* ── FAQ ── */
#faq{background:white}
.faq-list{max-width:780px;margin:0 auto;display:flex;flex-direction:column;gap:.85rem}
.faq-item{border:1px solid rgba(169,204,227,.4);border-radius:14px;background:var(--off-white);overflow:hidden}
.faq-q{width:100%;text-align:left;background:none;border:none;padding:1.15rem 1.4rem;font-family:inherit;font-size:1rem;font-weight:600;color:var(--text);cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.faq-icon{flex-shrink:0;transition:transform .3s;color:var(--sky-dark);font-size:1.4rem;line-height:1}
.faq-item.open .faq-icon{transform:rotate(45deg)}
.faq-a{max-height:0;overflow:hidden;transition:max-height .35s ease}
.faq-a p{padding:0 1.4rem 1.2rem;font-size:.9rem;line-height:1.7;color:var(--text-soft);font-weight:300}

/* ── RESPONSIVE ── */
/* Responsive media query adjustments */
@media(max-width:900px){
  .hero-inner,.about-grid,.contact-wrap{grid-template-columns:1fr;gap:2.5rem}
  .about-img-box{max-height:360px}
  .carriers-track{animation-duration:16s}
  .gallery-grid{grid-template-columns:repeat(2,1fr)}
  .video-grid{grid-template-columns:1fr}
}
@media(max-width:600px){
  .nav-links{display:none}
  .services-grid{grid-template-columns:1fr}
  .testimonials-grid{grid-template-columns:1fr}
  .hero-isabel{max-width:280px}
  .cms-notice-bar p{font-size:.72rem}
  .gallery-grid{grid-auto-rows:150px}
  .gallery-item.wide{grid-column:span 2}
  .gallery-item.tall{grid-row:span 1}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════════
     CMS NOTICE BAR — REQUIRED BY CMS MARKETING GUIDELINES
     Must appear prominently on every page that discusses Medicare plans.
     (Previously: CSS existed but this HTML element was NEVER rendered — fixed.)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="sticky-header">
<div class="cms-notice-bar" role="note" aria-label="Important Medicare disclosure">
  <p><?= $t['cms_notice'] ?></p>
</div>

<nav id="main-nav">
  <a href="#" class="nav-logo">
    <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/logoMWI.png" alt="Medicare with Isabel" class="nav-logo-img"/>
  </a>
  <div class="nav-right">
    <ul class="nav-links">
      <li><a href="#about"><?= $t['nav_about'] ?></a></li>
      <li><a href="#services"><?= $t['nav_services'] ?></a></li>
      <li><a href="#stories"><?= $lang==='es' ? 'Historias' : 'Stories' ?></a></li>
      <li><a href="#testimonials"><?= $t['nav_reviews'] ?></a></li>
      <li><a href="#contact" class="nav-cta"><?= $t['nav_contact'] ?></a></li>
    </ul>
    <div class="lang-toggle">
      <a href="<?= langUrl('en') ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>">EN</a>
      <a href="<?= langUrl('es') ?>" class="lang-btn <?= $lang==='es'?'active':'' ?>">ES</a>
    </div>
  </div>
</nav>
</div><!-- /.sticky-header -->

<!-- ── HERO ── -->
<section id="hero">
  <div class="hero-blob blob-1"></div>
  <div class="hero-blob blob-2"></div>
  <div class="hero-inner">
    <div class="hero-text">
      <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/logoMWI.png" alt="Medicare with Isabel" class="hero-logo-big fade-in"/>
      <p class="hero-location">📍 Serving Southern California</p>
      <h1 class="fade-in d1"><?= $t['hero_h1'] ?></h1>
      <p class="hero-sub fade-in d2"><?= $t['hero_sub'] ?></p>
      <div class="hero-actions fade-in d3">
        <a href="tel:+13102700626" class="btn-primary"><?= $t['hero_cta1'] ?></a>
        <a href="<?= htmlspecialchars($quoteHref) ?>" class="btn-secondary"<?= $quoteAttrs ?>><?= $t['hero_cta3'] ?></a>
        <a href="#services" class="btn-secondary"><?= $t['hero_cta2'] ?></a>
      </div>
    </div>
    <div class="hero-photo-wrap fade-in d4">
      <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/isabel-transparente.png" alt="Isabel Fuentes, Licensed Medicare Agent" class="hero-isabel"/>
      <div class="hero-stats-float">
        <div class="hero-stats">
          <div class="stat"><div class="stat-num" data-count="10" data-suffix="+">0</div><div class="stat-label"><?= $t['stat1'] ?></div></div>
          <div class="stat"><div class="stat-num" data-count="7" data-suffix="+">0</div><div class="stat-label"><?= $t['stat2'] ?></div></div>
          <div class="stat"><div class="stat-num">✓</div><div class="stat-label"><?= $t['stat3'] ?></div></div>
          <div class="stat"><div class="stat-num">🌐</div><div class="stat-label"><?= $t['stat4'] ?></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════
     CARRIERS SECTION — redesigned with scrolling marquee, colored cards,
     and mandatory CMS disclaimer ("we do not offer every plan")
     ══════════════════════════════════════════════════════════════════════ -->
<section id="carriers" aria-label="Insurance carriers we work with">
  <div class="carriers-inner">
    <p class="carriers-heading"><?= $t['carriers_section_label'] ?></p>
    <p class="carriers-sub"><?= $t['carriers_section_sub'] ?></p>

    <?php
    // Each carrier: [css-class, initials, display name]
    $carriers = [
      ['cc-scan',   'SC', 'SCAN Health Plan'],
      ['cc-anthem', 'AN', 'Anthem Blue Cross'],
      ['cc-humana', 'HU', 'Humana'],
      ['cc-align',  'AL', 'Alignment Health'],
      ['cc-lacare', 'LA', 'LA Care'],
      ['cc-hnet',   'HN', 'Health Net'],
      ['cc-molina', 'MO', 'Molina Healthcare'],
    ];
    ?>
    <div class="carriers-track-wrap" role="list" aria-label="Carrier list">
      <!-- Track is duplicated so the infinite scroll looks seamless -->
      <div class="carriers-track" aria-hidden="false">
        <?php foreach(array_merge($carriers, $carriers) as $c): ?>
          <div class="carrier-card <?= $c[0] ?>" role="listitem">
            <div class="carrier-dot" aria-hidden="true"><?= $c[1] ?></div>
            <span class="carrier-name-text"><?= $c[2] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- CMS: Must disclose that agent does not represent all plans. -->
    <p class="carriers-cms-note">
      <?= $lang === 'es'
        ? 'No estamos afiliados ni respaldados por el gobierno de los EE. UU. ni por el programa federal de Medicare. La disponibilidad de planes varía por ubicación. Visita <a href="https://www.medicare.gov" target="_blank" rel="noopener">Medicare.gov</a> para ver todas las opciones disponibles.'
        : 'We are not affiliated with or endorsed by the U.S. Government or the federal Medicare program. Plan availability varies by location. Visit <a href="https://www.medicare.gov" target="_blank" rel="noopener">Medicare.gov</a> to see all options available in your area.'
      ?>
    </p>
  </div>
</section>

<!-- ── ABOUT ── -->
<section id="about">
  <div class="section-inner">
    <div class="about-grid">
      <div class="about-img-wrap">
        <div class="about-img-box">
          <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/isabel-transparente.png" alt="Isabel Fuentes"/>
        </div>
        <div class="about-float">
          <p><?= $t['about_badge_l'] ?></p>
          <strong><?= $t['about_badge_v'] ?></strong>
        </div>
      </div>
      <div class="about-text">
        <div class="section-header">
          <div class="section-label"><?= $t['about_label'] ?></div>
          <h2 class="section-title"><?= $t['about_title'] ?></h2>
        </div>
        <p><?= $t['about_p1'] ?></p>
        <p><?= $t['about_p2'] ?></p>
        <p><?= $t['about_p3'] ?></p>
        <div class="highlights">
          <div class="highlight"><?= $t['hl1'] ?></div>
          <div class="highlight"><?= $t['hl2'] ?></div>
          <div class="highlight"><?= $t['hl3'] ?></div>
          <div class="highlight"><?= $t['hl4'] ?></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── SERVICES ── -->
<section id="services">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-label"><?= $t['svc_label'] ?></div>
      <h2 class="section-title"><?= $t['svc_title'] ?></h2>
      <p class="section-sub"><?= $t['svc_sub'] ?></p>
    </div>
    <div class="services-grid">
      <?php foreach([
        ['🏥','s1_title','s1_body'],
        ['📄','s2_title','s2_body'],
        ['🚗','s3_title','s3_body'],
        ['📞','s4_title','s4_body'],
        ['🦷','s5_title','s5_body'],
        ['💊','s6_title','s6_body'],
      ] as $s): ?>
        <div class="service-card">
          <div class="svc-icon"><?= $s[0] ?></div>
          <h3><?= $t[$s[1]] ?></h3>
          <p><?= $t[$s[2]] ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════
     PHOTO GALLERY — real photos of Isabel, clients, community, office.
     TO ADD A PHOTO: inside a .gallery-item, replace the <div class="gallery-ph">
     placeholder with:  <img src="YOUR_PHOTO_URL" alt="..."/>
     Keep the .wide / .tall classes to vary the layout. Clicking opens a lightbox.
     ══════════════════════════════════════════════════════════════════════ -->
<section id="gallery">
  <div class="section-inner">
    <div class="section-header reveal" style="text-align:center;max-width:560px;margin-left:auto;margin-right:auto">
      <div class="section-label"><?= $t['gallery_label'] ?></div>
      <h2 class="section-title"><?= $t['gallery_title'] ?></h2>
      <p class="section-sub" style="margin:0 auto"><?= $t['gallery_sub'] ?></p>
    </div>
    <?php
    // [layout-class, EN caption, ES caption, image URL ('' = placeholder tile)]
    // NOTE: the URLs below are TEMPORARY demo photos so you can preview the
    // gallery + lightbox. Replace each with your own photo URL when ready.
    $gallery = [
      ['wide', 'Enrollment day with a client',   'Día de inscripción con un cliente', 'https://picsum.photos/seed/isabel-enroll/1200/800'],
      ['',     'Community health fair',           'Feria de salud comunitaria',        'https://picsum.photos/seed/isabel-fair/900/900'],
      ['tall', 'Helping at the office',           'Ayudando en la oficina',            'https://picsum.photos/seed/isabel-office/800/1200'],
      ['',     'Spanish-language workshop',       'Taller en español',                 'https://picsum.photos/seed/isabel-workshop/900/900'],
      ['wide', 'Client appreciation day',         'Día de agradecimiento al cliente',  'https://picsum.photos/seed/isabel-clients/1200/800'],
      ['',     'Q&amp;A at the senior center',    'Preguntas en el centro de ancianos','https://picsum.photos/seed/isabel-seniors/900/900'],
    ];
    ?>
    <div class="gallery-grid reveal">
      <?php foreach($gallery as $g): $cap = $lang==='es' ? $g[2] : $g[1]; ?>
        <div class="gallery-item <?= $g[0] ?>"<?= $g[3] ? ' data-full="'.htmlspecialchars($g[3]).'"' : '' ?>>
          <?php if($g[3]): ?>
            <img src="<?= htmlspecialchars($g[3]) ?>" alt="<?= $cap ?>"/>
            <div class="g-cap"><?= $cap ?></div>
          <?php else: ?>
            <div class="gallery-ph"><span>🖼️</span><?= $lang==='es' ? 'Agrega una foto' : 'Add a photo' ?><br><?= $cap ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════════════════════════════
     VIDEO STORIES — short client success-story videos.
     TO ADD A VIDEO: put the YouTube video ID (the part after watch?v=) in the
     first array slot, e.g. 'dQw4w9WgXcQ'. Leave '' to show a placeholder tile.
     Videos load only when clicked (keeps the page fast).
     ══════════════════════════════════════════════════════════════════════ -->
<section id="stories">
  <div class="section-inner">
    <div class="section-header reveal" style="text-align:center;max-width:560px;margin-left:auto;margin-right:auto">
      <div class="section-label"><?= $t['video_label'] ?></div>
      <h2 class="section-title"><?= $t['video_title'] ?></h2>
      <p class="section-sub" style="margin:0 auto"><?= $t['video_sub'] ?></p>
    </div>
    <?php
    // [youtube-id ('' = placeholder), EN title, ES title, location]
    $videos = [
      ['', 'Maria found a plan that fit',  'María encontró el plan ideal',      'Los Angeles, CA'],
      ['', 'Roberto saved on his meds',    'Roberto ahorró en sus medicinas',   'Inglewood, CA'],
      ['', 'Patricia got year-round help', 'Patricia recibió ayuda todo el año','Compton, CA'],
    ];
    ?>
    <div class="video-grid reveal">
      <?php foreach($videos as $v): $vtitle = $lang==='es' ? $v[2] : $v[1]; ?>
        <div class="video-card">
          <div class="video-frame"<?= $v[0] ? ' data-yt="'.htmlspecialchars($v[0]).'"' : '' ?>>
            <?php if($v[0]): ?>
              <img class="video-thumb" src="https://img.youtube.com/vi/<?= htmlspecialchars($v[0]) ?>/hqdefault.jpg" alt="<?= $vtitle ?>"/>
              <div class="video-play"></div>
            <?php else: ?>
              <div class="video-ph">🎬<br><?= $lang==='es' ? 'Agrega un video aquí' : 'Add a video here' ?></div>
            <?php endif; ?>
          </div>
          <div class="video-meta">
            <h4><?= $vtitle ?></h4>
            <p>📍 <?= $v[3] ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── TESTIMONIALS ── -->
<section id="testimonials">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-label"><?= $t['test_label'] ?></div>
      <h2 class="section-title"><?= $t['test_title'] ?></h2>
      <p class="section-sub"><?= $t['test_sub'] ?></p>
    </div>
    <?php
    // [initials, body-key, name, city] — add more rows and the slider adapts.
    $testimonials = [
      ['MG','t1_body','Maria G.','Los Angeles, CA'],
      ['RL','t2_body','Roberto L.','Inglewood, CA'],
      ['PS','t3_body','Patricia S.','Compton, CA'],
      ['CM','t4_body','Carlos M.','Long Beach, CA'],
      ['LT','t5_body','Linda T.','Torrance, CA'],
      ['GR','t6_body','Gloria R.','Lynwood, CA'],
    ];
    ?>
    <div class="t-slider" id="tSlider">
      <div class="t-track" id="tTrack">
        <?php foreach($testimonials as $tm): ?>
          <div class="t-slide">
            <div class="testimonial-card">
              <div class="quote-mark">"</div>
              <p><?= $t[$tm[1]] ?></p>
              <div class="t-author">
                <div class="t-avatar"><?= $tm[0] ?></div>
                <div><div class="t-name"><?= $tm[2] ?></div><div class="t-city"><?= $tm[3] ?></div></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="t-controls">
      <button class="t-arrow" id="tPrev" aria-label="<?= $lang==='es'?'Anterior':'Previous' ?>">‹</button>
      <div class="t-dots" id="tDots"></div>
      <button class="t-arrow" id="tNext" aria-label="<?= $lang==='es'?'Siguiente':'Next' ?>">›</button>
    </div>
  </div>
</section>

<!-- ── FAQ (accordion) ── -->
<section id="faq">
  <div class="section-inner">
    <div class="section-header reveal" style="text-align:center;max-width:560px;margin-left:auto;margin-right:auto">
      <div class="section-label"><?= $t['faq_label'] ?></div>
      <h2 class="section-title"><?= $t['faq_title'] ?></h2>
      <p class="section-sub" style="margin:0 auto"><?= $t['faq_sub'] ?></p>
    </div>
    <div class="faq-list reveal">
      <?php foreach(['1','2','3','4','5'] as $n): ?>
        <div class="faq-item">
          <button class="faq-q" type="button" aria-expanded="false">
            <span><?= $t['faq_q'.$n] ?></span>
            <span class="faq-icon" aria-hidden="true">+</span>
          </button>
          <div class="faq-a"><p><?= $t['faq_a'.$n] ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── CONTACT + FORM ── -->
<section id="contact">
  <div class="section-inner">
    <div class="contact-wrap">
      <div class="contact-info">
        <div class="section-label"><?= $t['contact_label'] ?></div>
        <h2 class="section-title"><?= $t['contact_title'] ?></h2>
        <p><?= $t['contact_sub'] ?></p>
        <div class="contact-methods">
          <a href="tel:+13102700626" class="c-method">
            <div class="c-icon">📞</div>
            <div><div class="c-label"><?= $t['method_call'] ?></div><div class="c-value">(310) 270-0626</div></div>
          </a>
          <a href="mailto:Connect@withisabelfuentes.com" class="c-method">
            <div class="c-icon">✉️</div>
            <div><div class="c-label"><?= $t['method_email'] ?></div><div class="c-value">Connect@withisabelfuentes.com</div></div>
          </a>
          <a href="https://withisabelfuentes.com" class="c-method">
            <div class="c-icon">🌐</div>
            <div><div class="c-label"><?= $t['method_web'] ?></div><div class="c-value">withisabelfuentes.com</div></div>
          </a>
        </div>
      </div>

      <div class="form-box">
        <!-- Connecture quoting link — opens in new tab -->
        <a href="<?= htmlspecialchars($quoteHref) ?>" class="form-submit"<?= $quoteAttrs ?> style="display:block;text-align:center;text-decoration:none;margin-bottom:1.25rem;">
          📋 <?= $t['hero_cta3'] ?>
        </a>
        <p style="text-align:center;font-size:.78rem;color:var(--text-soft);margin-bottom:1.5rem;">— <?= $lang==='es' ? 'o envíanos un mensaje' : 'or send us a message' ?> —</p>
        <h3><?= $t['form_title'] ?></h3>

        <?php if ($formMessage): ?>
          <div class="form-alert <?= $formSuccess ? 'success' : 'error' ?>" role="alert">
            <?= $formMessage ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="#contact" class="form-inner" novalidate>
          <input type="hidden" name="form_submit" value="1"/>
          <input type="hidden" name="form_lang"   value="<?= $lang ?>"/>
          <!-- CSRF token — prevents cross-site request forgery -->
          <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
          <!-- Honeypot — bots fill this, humans don't -->
          <div class="form-honeypot" aria-hidden="true">
            <label for="website_url">Leave this blank</label>
            <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off"/>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="fname"><?= $t['form_fname'] ?> *</label>
              <input type="text" id="fname" name="fname" placeholder="<?= $t['ph_fname'] ?>" required autocomplete="given-name"/>
            </div>
            <div class="form-group">
              <label for="lname"><?= $t['form_lname'] ?></label>
              <input type="text" id="lname" name="lname" placeholder="<?= $t['ph_lname'] ?>" autocomplete="family-name"/>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="phone"><?= $t['form_phone'] ?> *</label>
              <input type="tel" id="phone" name="phone" placeholder="(310) 000-0000" required autocomplete="tel"/>
            </div>
            <div class="form-group">
              <label for="email"><?= $t['form_email'] ?></label>
              <input type="email" id="email" name="email" placeholder="maria@email.com" autocomplete="email"/>
            </div>
          </div>
          <div class="form-group">
            <label for="interest"><?= $t['form_interest'] ?></label>
            <select id="interest" name="interest">
              <?php foreach(['opt1','opt2','opt3','opt4','opt5'] as $o): ?>
                <option value="<?= $t[$o] ?>"><?= $t[$o] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="message"><?= $t['form_msg'] ?></label>
            <textarea id="message" name="message" placeholder="<?= $t['ph_msg'] ?>"></textarea>
          </div>

          <!-- CMS: SOA note — agent must disclose scope before discussing plan details -->
          <p class="form-soa-note">
            <?= $lang === 'es'
              ? '📋 Al enviar este formulario, usted confirma que desea hablar con un agente de seguros con licencia sobre sus opciones de Medicare. No hay costo para usted por nuestros servicios.'
              : '📋 By submitting this form, you confirm you would like to speak with a licensed insurance agent about your Medicare options. There is no cost to you for our services.'
            ?>
          </p>

          <button type="submit" class="form-submit"><?= $t['form_btn'] ?></button>
        </form>
      </div>
    </div>
  </div>
</section>

<!-- ── FOOTER ── -->
<footer>
  <div class="footer-inner">
    <div class="footer-logo">
      <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/logoMWI.png" alt="Medicare with Isabel"/>
    </div>
    <div class="footer-links">
      <a href="#about"><?= $t['nav_about'] ?></a>
      <a href="#services"><?= $t['nav_services'] ?></a>
      <a href="#testimonials"><?= $t['nav_reviews'] ?></a>
      <a href="#contact"><?= $t['nav_contact'] ?></a>
    </div>
    <p>© <?= date('Y') ?> <strong>Medicare with Isabel | Isabel Fuentes</strong> · <?= $t['footer_lic'] ?></p>
    <p class="footer-disc"><?= $t['footer_disc'] ?></p>
  </div>
</footer>

<!-- Lightbox for the photo gallery -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true">
  <button class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
  <img id="lightboxImg" src="" alt=""/>
</div>

<script>
// ── Smooth scroll ──
document.querySelectorAll('a[href^="#"]').forEach(a => a.addEventListener('click', e => {
  const target = document.querySelector(a.getAttribute('href'));
  if (target) { e.preventDefault(); target.scrollIntoView({behavior:'smooth'}); }
}));

// ── Scroll-in animations (cards + .reveal blocks) ──
const revealObs = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); revealObs.unobserve(e.target); } });
}, {threshold:0.12});
document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));
document.querySelectorAll('.service-card,.about-img-wrap,.about-text').forEach(el => {
  el.style.cssText += 'opacity:0;transform:translateY(28px);transition:opacity .6s ease,transform .6s ease';
  new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.style.opacity='1'; e.target.style.transform='translateY(0)'; } });
  }, {threshold:0.1}).observe(el);
});

// ── Animated count-up stats ──
document.querySelectorAll('[data-count]').forEach(el => {
  const target = +el.dataset.count, suffix = el.dataset.suffix || '';
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      io.unobserve(el);
      const dur = 1200, start = performance.now();
      const tick = now => {
        const p = Math.min((now - start) / dur, 1);
        el.textContent = Math.round(p * target) + (p === 1 ? suffix : '');
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }, {threshold:0.5});
  io.observe(el);
});

// ── Testimonial slider ──
(function(){
  const track = document.getElementById('tTrack');
  if (!track) return;
  const slides = track.children.length;
  const dotsWrap = document.getElementById('tDots');
  let index = 0, autoplay;
  const perView = () => window.innerWidth >= 760 ? 2 : 1;
  const maxIndex = () => Math.max(0, slides - perView());

  function build(){
    dotsWrap.innerHTML = '';
    for (let i = 0; i <= maxIndex(); i++){
      const d = document.createElement('button');
      d.className = 't-dot' + (i === index ? ' active' : '');
      d.setAttribute('aria-label', 'Slide ' + (i+1));
      d.addEventListener('click', () => { index = i; render(); reset(); });
      dotsWrap.appendChild(d);
    }
  }
  function render(){
    index = Math.min(index, maxIndex());
    track.style.transform = 'translateX(-' + (index * (100 / perView())) + '%)';
    [...dotsWrap.children].forEach((d,i) => d.classList.toggle('active', i === index));
  }
  function next(){ index = index >= maxIndex() ? 0 : index + 1; render(); }
  function prev(){ index = index <= 0 ? maxIndex() : index - 1; render(); }
  function reset(){ clearInterval(autoplay); autoplay = setInterval(next, 5500); }

  document.getElementById('tNext').addEventListener('click', () => { next(); reset(); });
  document.getElementById('tPrev').addEventListener('click', () => { prev(); reset(); });
  window.addEventListener('resize', () => { build(); render(); });

  // Touch swipe
  let x0 = null;
  track.addEventListener('touchstart', e => x0 = e.touches[0].clientX, {passive:true});
  track.addEventListener('touchend', e => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 40) { dx < 0 ? next() : prev(); reset(); }
    x0 = null;
  });

  build(); render(); reset();
})();

// ── FAQ accordion ──
document.querySelectorAll('.faq-q').forEach(btn => btn.addEventListener('click', () => {
  const item = btn.parentElement, ans = item.querySelector('.faq-a'), open = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(o => {
    o.classList.remove('open'); o.querySelector('.faq-a').style.maxHeight = null;
    o.querySelector('.faq-q').setAttribute('aria-expanded','false');
  });
  if (!open){
    item.classList.add('open'); ans.style.maxHeight = ans.scrollHeight + 'px';
    btn.setAttribute('aria-expanded','true');
  }
}));

// ── Lazy-load video stories on click ──
document.querySelectorAll('.video-frame[data-yt]').forEach(frame => frame.addEventListener('click', () => {
  const id = frame.dataset.yt;
  frame.innerHTML = '<iframe src="https://www.youtube.com/embed/' + id + '?autoplay=1&rel=0" '
    + 'title="Client story" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe>';
}));

// ── Gallery lightbox ──
(function(){
  const lb = document.getElementById('lightbox'), lbImg = document.getElementById('lightboxImg');
  document.querySelectorAll('.gallery-item[data-full]').forEach(item => item.addEventListener('click', () => {
    lbImg.src = item.dataset.full; lb.classList.add('open');
  }));
  const close = () => { lb.classList.remove('open'); lbImg.src = ''; };
  document.getElementById('lightboxClose').addEventListener('click', close);
  lb.addEventListener('click', e => { if (e.target === lb) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>
</body>
</html>
