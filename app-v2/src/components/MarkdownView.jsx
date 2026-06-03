// Renderer markdown minimal — solo lo que usan nuestros docs:
// # h1, ## h2, ### h3, párrafos, listas con -, **bold**, `code`,
// código en bloques con ```, tablas básicas con |, blockquote >, hr ---
// Sin libraries — directo a JSX. Suficiente para documentos curados.

function renderInline(text) {
  // Procesa **bold** y `code` inline. Cuida no escape HTML — confiamos
  // en que el content viene del repo, no de input usuario.
  const parts = [];
  let i = 0;
  let key = 0;
  while (i < text.length) {
    // **bold**
    if (text[i] === '*' && text[i + 1] === '*') {
      const end = text.indexOf('**', i + 2);
      if (end > 0) {
        parts.push(<strong key={key++}>{text.slice(i + 2, end)}</strong>);
        i = end + 2;
        continue;
      }
    }
    // `code`
    if (text[i] === '`') {
      const end = text.indexOf('`', i + 1);
      if (end > 0) {
        parts.push(<code key={key++} className="bg-lino-100 text-lino-800 px-1.5 py-0.5 rounded text-xs font-mono">{text.slice(i + 1, end)}</code>);
        i = end + 1;
        continue;
      }
    }
    // Plain run hasta el próximo special
    let nextSpecial = text.length;
    for (let j = i + 1; j < text.length; j++) {
      if (text[j] === '`' || (text[j] === '*' && text[j + 1] === '*')) { nextSpecial = j; break; }
    }
    parts.push(text.slice(i, nextSpecial));
    i = nextSpecial;
  }
  return parts;
}

function parseTable(lines, startIdx) {
  // Espera header | separator (---) | rows
  const headerLine = lines[startIdx];
  const sepLine = lines[startIdx + 1];
  if (!sepLine || !/^\s*\|?\s*:?-+:?(\s*\|\s*:?-+:?)+\s*\|?\s*$/.test(sepLine)) return null;
  const splitRow = (l) => l.split('|').map((c) => c.trim()).filter((_, idx, arr) => idx > 0 || arr[0].length > 0).map((c, idx, arr) => {
    // remove leading/trailing empties caused by leading/trailing pipes
    return c;
  });
  // Simpler: trim leading/trailing pipes, then split
  const cells = (l) => l.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map((c) => c.trim());
  const headers = cells(headerLine);
  const rows = [];
  let i = startIdx + 2;
  while (i < lines.length && lines[i].includes('|') && lines[i].trim()) {
    rows.push(cells(lines[i]));
    i += 1;
  }
  return { headers, rows, endIdx: i };
}

export default function MarkdownView({ content }) {
  if (!content) return null;
  const lines = content.split('\n');
  const elements = [];
  let i = 0;
  let key = 0;
  let inCodeBlock = false;
  let codeBuffer = [];

  while (i < lines.length) {
    const line = lines[i];

    // Fenced code block ```
    if (/^```/.test(line)) {
      if (inCodeBlock) {
        elements.push(<pre key={key++} className="bg-lino-100 text-ink-1 p-3 rounded text-xs font-mono overflow-x-auto whitespace-pre">{codeBuffer.join('\n')}</pre>);
        codeBuffer = [];
        inCodeBlock = false;
      } else {
        inCodeBlock = true;
      }
      i += 1;
      continue;
    }
    if (inCodeBlock) {
      codeBuffer.push(line);
      i += 1;
      continue;
    }

    // Headings
    if (/^# /.test(line)) {
      elements.push(<h1 key={key++} className="font-serif text-3xl text-lino-800 mt-6 mb-3">{renderInline(line.slice(2))}</h1>);
      i += 1;
      continue;
    }
    if (/^## /.test(line)) {
      elements.push(<h2 key={key++} className="font-serif text-2xl text-lino-800 mt-6 mb-2">{renderInline(line.slice(3))}</h2>);
      i += 1;
      continue;
    }
    if (/^### /.test(line)) {
      elements.push(<h3 key={key++} className="font-medium text-lg text-ink-1 mt-4 mb-2">{renderInline(line.slice(4))}</h3>);
      i += 1;
      continue;
    }
    if (/^#### /.test(line)) {
      elements.push(<h4 key={key++} className="font-medium text-base text-ink-1 mt-3 mb-1">{renderInline(line.slice(5))}</h4>);
      i += 1;
      continue;
    }

    // Hr ---
    if (/^---+\s*$/.test(line)) {
      elements.push(<hr key={key++} className="my-6 border-lino-200" />);
      i += 1;
      continue;
    }

    // Table
    if (line.includes('|') && i + 1 < lines.length && /^\s*\|?\s*:?-+:?(\s*\|\s*:?-+:?)+\s*\|?\s*$/.test(lines[i + 1])) {
      const tbl = parseTable(lines, i);
      if (tbl) {
        elements.push(
          <div key={key++} className="overflow-x-auto my-3">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-lino-300">
                  {tbl.headers.map((h, idx) => (
                    <th key={idx} className="text-left px-2 py-1.5 font-medium text-ink-2">{renderInline(h)}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {tbl.rows.map((row, idx) => (
                  <tr key={idx} className="border-b border-lino-100">
                    {row.map((cell, jdx) => (
                      <td key={jdx} className="px-2 py-1.5 text-ink-2 align-top">{renderInline(cell)}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        );
        i = tbl.endIdx;
        continue;
      }
    }

    // Blockquote >
    if (/^>\s/.test(line)) {
      const text = line.slice(2);
      elements.push(<blockquote key={key++} className="border-l-4 border-lino-400 pl-3 py-1 my-2 text-ink-2 italic">{renderInline(text)}</blockquote>);
      i += 1;
      continue;
    }

    // Listas con -
    if (/^[-*]\s/.test(line)) {
      const items = [];
      while (i < lines.length && /^[-*]\s/.test(lines[i])) {
        items.push(lines[i].replace(/^[-*]\s/, ''));
        i += 1;
      }
      elements.push(
        <ul key={key++} className="list-disc list-inside my-2 space-y-1 text-sm text-ink-2">
          {items.map((it, idx) => <li key={idx}>{renderInline(it)}</li>)}
        </ul>
      );
      continue;
    }

    // Listas numeradas
    if (/^\d+\.\s/.test(line)) {
      const items = [];
      while (i < lines.length && /^\d+\.\s/.test(lines[i])) {
        items.push(lines[i].replace(/^\d+\.\s/, ''));
        i += 1;
      }
      elements.push(
        <ol key={key++} className="list-decimal list-inside my-2 space-y-1 text-sm text-ink-2">
          {items.map((it, idx) => <li key={idx}>{renderInline(it)}</li>)}
        </ol>
      );
      continue;
    }

    // Empty line — párrafo break
    if (!line.trim()) {
      i += 1;
      continue;
    }

    // Default: párrafo
    elements.push(<p key={key++} className="text-sm text-ink-2 my-2 leading-relaxed">{renderInline(line)}</p>);
    i += 1;
  }

  return <div className="prose-isabel max-w-none">{elements}</div>;
}
