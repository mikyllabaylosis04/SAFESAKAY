export type ReportExportColumn = {
    key: string;
    header: string;
};

export type ReportExportRow = Record<string, string | number | null | undefined>;

export type ReportExportMeta = {
    label: string;
    value: string;
};

export type ReportExportSpec = {
    title: string;
    subtitle: string;
    filename: string;
    columns: ReportExportColumn[];
    rows: ReportExportRow[];
    organization?: string;
    tagline?: string;
    sectionTitle?: string;
    meta?: ReportExportMeta[];
    note?: string;
    emptyMessage?: string;
    stats?: ReportExportMeta[];
};

export type ReportDownloadFormat = 'pdf' | 'excel' | 'csv' | 'print';

type LogoImage = {
    bytes: Uint8Array;
    width: number;
    height: number;
};

function cellValue(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}

function csvCell(value: string | number | null | undefined): string {
    const text = cellValue(value);

    if (/[",\n]/.test(text)) {
        return `"${text.replaceAll('"', '""')}"`;
    }

    return text;
}

function escapeHtml(value: string | number | null | undefined): string {
    return cellValue(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function triggerDownload(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1500);
}

function logoUrl(): string {
    if (typeof window === 'undefined') {
        return '/images/safesakay-logo.png?v=2';
    }

    return `${window.location.origin}/images/safesakay-logo.png?v=2`;
}

function generatedAt(): string {
    return new Date().toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function officialTagline(spec: ReportExportSpec): string {
    return spec.tagline || 'Official report';
}

function officialMeta(spec: ReportExportSpec): ReportExportMeta[] {
    if (spec.meta && spec.meta.length > 0) {
        return spec.meta;
    }

    return [
        { label: 'Period', value: spec.subtitle },
        { label: 'Generated', value: generatedAt() },
        { label: 'Records', value: String(spec.rows.length) },
    ];
}

function officialNote(spec: ReportExportSpec): string {
    return (
        spec.note ||
        'This report is generated from SafeSakay for authorized administrators and official barangay records only.'
    );
}

function officialEmptyMessage(spec: ReportExportSpec): string {
    return spec.emptyMessage || 'No records for this report.';
}

function statsHtml(spec: ReportExportSpec): string {
    if (!spec.stats || spec.stats.length === 0) {
        return '';
    }

    return `<div class="stats">${spec.stats
        .map(
            (item) =>
                `<div><span>${escapeHtml(item.label)}</span><strong>${escapeHtml(item.value)}</strong></div>`,
        )
        .join('')}</div>`;
}

function metaHtml(spec: ReportExportSpec): string {
    return `<div class="meta">${officialMeta(spec)
        .map(
            (item) =>
                `<div><span>${escapeHtml(item.label)}</span><strong>${escapeHtml(item.value)}</strong></div>`,
        )
        .join('')}</div>`;
}

function tableHtml(spec: ReportExportSpec): string {
    const headers = spec.columns
        .map((column) => `<th>${escapeHtml(column.header)}</th>`)
        .join('');
    const body =
        spec.rows.length === 0
            ? `<tr><td colspan="${spec.columns.length}">${escapeHtml(officialEmptyMessage(spec))}</td></tr>`
            : spec.rows
                .map((row) => {
                    const cells = spec.columns
                        .map((column) => `<td>${escapeHtml(row[column.key])}</td>`)
                        .join('');

                    return `<tr>${cells}</tr>`;
                })
                .join('');

    return `<table>
        <thead><tr>${headers}</tr></thead>
        <tbody>${body}</tbody>
    </table>`;
}

function printableHtml(spec: ReportExportSpec): string {
    const organization = spec.organization || 'SafeSakay';
    const sectionTitle = spec.sectionTitle || 'Report Details';

    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${escapeHtml(spec.filename || spec.title)}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef5fb;
            color: #163a5f;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .toolbar {
            position: sticky;
            top: 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 16px;
            background: #fff;
            border-bottom: 1px solid #d7e8f6;
        }
        .toolbar button {
            height: 38px;
            border: 0;
            border-radius: 10px;
            padding: 0 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .print { background: #1b7ad8; color: #fff; }
        .close { background: #e8f4fc; color: #1b7ad8; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            background: #fff;
            padding: 22mm 16mm;
            box-shadow: 0 10px 30px rgba(15, 76, 138, 0.12);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #1b7ad8;
            padding-bottom: 14px;
        }
        .brand img { width: 56px; height: 56px; object-fit: contain; }
        h1 { margin: 0; font-size: 22px; color: #0f4c8a; }
        .sub { margin: 4px 0 0; color: #5b7c99; font-size: 13px; }
        h2 { margin: 22px 0 10px; font-size: 18px; color: #0f4c8a; }
        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
            margin: 16px 0 8px;
            font-size: 13px;
        }
        .meta span { color: #7d93a8; display: block; font-size: 11px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin: 8px 0 4px;
        }
        .stats div {
            border: 1px solid #d7e8f6;
            border-radius: 10px;
            padding: 10px 12px;
            background: #f7fbfe;
        }
        .stats span { color: #7d93a8; display: block; font-size: 11px; }
        .stats strong { display: block; margin-top: 4px; color: #0f4c8a; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        th, td { border: 1px solid #d7e8f6; padding: 8px 10px; text-align: left; }
        th { background: #f4f9fd; color: #5b7c99; font-size: 11px; text-transform: uppercase; }
        .note { margin-top: 18px; font-size: 11px; color: #7d93a8; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: auto; margin: 0; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="close" onclick="window.close()">Close</button>
        <button type="button" class="print" onclick="window.print()">Print</button>
    </div>
    <main class="sheet">
        <div class="brand">
            <img src="${logoUrl()}" alt="SafeSakay">
            <div>
                <h1>${escapeHtml(spec.title)}</h1>
                <p class="sub">${escapeHtml(organization)} · ${escapeHtml(officialTagline(spec))}</p>
            </div>
        </div>
        ${metaHtml(spec)}
        ${statsHtml(spec)}
        <h2>${escapeHtml(sectionTitle)}</h2>
        ${tableHtml(spec)}
        <p class="note">${escapeHtml(officialNote(spec))}</p>
    </main>
</body>
</html>`;
}

function openReportWindow(html: string) {
    const popup = window.open('', '_blank', 'width=1024,height=720');

    if (popup) {
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
        popup.focus();
        return;
    }

    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.srcdoc = html;
    iframe.addEventListener('load', () => {
        iframe.contentWindow?.focus();
        iframe.contentWindow?.print();
        window.setTimeout(() => iframe.remove(), 1000);
    });
    document.body.appendChild(iframe);
}

function latin1Bytes(text: string): Uint8Array {
    const bytes = new Uint8Array(text.length);

    for (let index = 0; index < text.length; index += 1) {
        bytes[index] = text.charCodeAt(index) & 0xff;
    }

    return bytes;
}

function concatBytes(chunks: Uint8Array[]): Uint8Array {
    const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
    const merged = new Uint8Array(total);
    let offset = 0;

    for (const chunk of chunks) {
        merged.set(chunk, offset);
        offset += chunk.length;
    }

    return merged;
}

function pdfSafeText(value: string): string {
    return value
        .replaceAll('\u2013', '-')
        .replaceAll('\u2014', '-')
        .replaceAll('\u2018', "'")
        .replaceAll('\u2019', "'")
        .replaceAll('\u201c', '"')
        .replaceAll('\u201d', '"')
        .replaceAll('\u2022', '*')
        .replaceAll('\u00a0', ' ')
        .replaceAll('\u2192', '->')
        .replaceAll('\u2194', '<->')
        .replaceAll('—', '-')
        .replaceAll('₱', 'PHP ')
        .replaceAll('\u20b1', 'PHP ')
        .split('')
        .map((character) => {
            const code = character.charCodeAt(0);

            if (code === 10 || code === 13) {
                return ' ';
            }

            if (code < 32) {
                return '';
            }

            if (code > 255) {
                return '?';
            }

            return character;
        })
        .join('');
}

function pdfString(value: string): string {
    let output = '(';

    for (const character of pdfSafeText(value)) {
        const code = character.charCodeAt(0);

        if (character === '\\' || character === '(' || character === ')') {
            output += `\\${character}`;
        } else if (code < 32 || code > 126) {
            output += `\\${code.toString(8).padStart(3, '0')}`;
        } else {
            output += character;
        }
    }

    return `${output})`;
}

function textWidth(value: string, fontSize: number): number {
    return pdfSafeText(cellValue(value)).length * fontSize * 0.5;
}

function wrapCell(value: string, maxWidth: number, fontSize: number): string[] {
    const text = pdfSafeText(cellValue(value));

    if (textWidth(text, fontSize) <= maxWidth) {
        return [text];
    }

    const words = text.split(/\s+/).filter(Boolean);
    const lines: string[] = [];
    let current = '';

    words.forEach((word) => {
        const next = current ? `${current} ${word}` : word;

        if (textWidth(next, fontSize) > maxWidth && current) {
            lines.push(current);
            current = word;
            return;
        }

        current = next;
    });

    if (current) {
        lines.push(current);
    }

    return lines.length > 0 ? lines : [''];
}

function fitText(value: string, maxWidth: number, fontSize: number): string {
    const lines = wrapCell(value, maxWidth, fontSize);

    return lines.join(' ');
}

async function loadLogoJpeg(): Promise<LogoImage | null> {
    try {
        const image = new Image();
        image.src = logoUrl();
        await image.decode();

        const size = 160;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const context = canvas.getContext('2d');

        if (!context) {
            return null;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, size, size);
        context.drawImage(image, 0, 0, size, size);

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, 'image/jpeg', 0.92);
        });

        if (!blob) {
            return null;
        }

        return {
            bytes: new Uint8Array(await blob.arrayBuffer()),
            width: size,
            height: size,
        };
    } catch {
        return null;
    }
}

function wrapText(value: string, maxWidth: number, fontSize: number): string[] {
    const words = pdfSafeText(value).split(/\s+/).filter(Boolean);
    const lines: string[] = [];
    let current = '';

    words.forEach((word) => {
        const next = current ? `${current} ${word}` : word;

        if (next.length * fontSize * 0.5 > maxWidth && current) {
            lines.push(current);
            current = word;
            return;
        }

        current = next;
    });

    if (current) {
        lines.push(current);
    }

    return lines.length > 0 ? lines : [''];
}

function columnWidths(spec: ReportExportSpec, tableWidth: number): number[] {
    const weights = spec.columns.map((column) => {
        const sample = spec.rows
            .slice(0, 12)
            .map((row) => cellValue(row[column.key]).length)
            .reduce((longest, length) => Math.max(longest, length), column.header.length);

        return Math.min(28, Math.max(6, sample));
    });
    const total = weights.reduce((sum, weight) => sum + weight, 0);

    return weights.map((weight) => (tableWidth * weight) / total);
}

async function buildPdf(spec: ReportExportSpec): Promise<Uint8Array> {
    const logo = await loadLogoJpeg();
    const wide = spec.columns.length > 8;
    const pageWidth = wide ? 842 : 595;
    const pageHeight = wide ? 595 : 842;
    const margin = wide ? 28 : 48;
    const tableWidth = pageWidth - margin * 2;
    const widths = columnWidths(spec, tableWidth);
    const fontSize = wide ? 8 : spec.columns.length > 7 ? 8 : 9;
    const organization = spec.organization || 'SafeSakay';
    const sectionTitle = spec.sectionTitle || 'Report Details';
    const note = officialNote(spec);
    const meta = [...officialMeta(spec), ...(spec.stats ?? [])];
    const pages: string[] = [];
    let commands: string[] = [];
    let cursorY = 0;

    const startPage = (includeDetails = true) => {
        commands = [];
        cursorY = pageHeight - 36;

        if (logo) {
            commands.push(`q 42 0 0 42 ${margin} ${cursorY - 36} cm /Im1 Do Q`);
        }

        const textX = logo ? margin + 54 : margin;
        commands.push('0.059 0.298 0.541 rg');
        commands.push(
            `BT /F2 16 Tf 1 0 0 1 ${textX} ${cursorY - 8} Tm ${pdfString(fitText(spec.title, tableWidth - 60, 16))} Tj ET`,
        );
        commands.push('0.357 0.486 0.600 rg');
        commands.push(
            `BT /F1 10 Tf 1 0 0 1 ${textX} ${cursorY - 24} Tm ${pdfString(`${organization} · ${officialTagline(spec)}`)} Tj ET`,
        );
        cursorY -= 48;
        commands.push('0.106 0.478 0.847 RG');
        commands.push('1.6 w');
        commands.push(`${margin} ${cursorY} m ${pageWidth - margin} ${cursorY} l S`);
        cursorY -= 22;

        if (includeDetails) {
            const columnTwo = margin + tableWidth / 2;

            meta.forEach((item, index) => {
                if (index > 0 && index % 2 === 0) {
                    cursorY -= 28;
                }

                const x = index % 2 === 0 ? margin : columnTwo;
                commands.push('0.490 0.576 0.659 rg');
                commands.push(`BT /F1 8 Tf 1 0 0 1 ${x} ${cursorY} Tm ${pdfString(item.label)} Tj ET`);
                commands.push('0.086 0.227 0.373 rg');
                commands.push(
                    `BT /F2 11 Tf 1 0 0 1 ${x} ${cursorY - 13} Tm ${pdfString(fitText(item.value, tableWidth / 2 - 8, 11))} Tj ET`,
                );
            });

            cursorY -= 36;
            commands.push('0.059 0.298 0.541 rg');
            commands.push(`BT /F2 14 Tf 1 0 0 1 ${margin} ${cursorY} Tm ${pdfString(sectionTitle)} Tj ET`);
            cursorY -= 16;
        } else {
            cursorY -= 14;
        }

        drawRow(spec.columns.map((column) => column.header), true);
    };

    const drawRow = (values: string[], header = false) => {
        const cells = values.map((value, index) => wrapCell(value, (widths[index] ?? 60) - 8, fontSize));
        const lineCount = Math.max(1, ...cells.map((lines) => lines.length));
        const rowHeight = header ? Math.max(22, lineCount * 10 + 8) : Math.max(16, lineCount * 10 + 6);
        let x = margin;

        commands.push(header ? '0.957 0.976 0.992 rg' : '1 1 1 rg');
        commands.push(`${margin} ${cursorY - rowHeight} ${tableWidth} ${rowHeight} re f`);
        commands.push('0.843 0.910 0.965 RG');
        commands.push('0.6 w');
        commands.push(`${margin} ${cursorY - rowHeight} ${tableWidth} ${rowHeight} re S`);
        commands.push(header ? '0.357 0.486 0.600 rg' : '0.086 0.227 0.373 rg');

        cells.forEach((lines, index) => {
            const width = widths[index] ?? 60;
            commands.push('0.843 0.910 0.965 RG');
            commands.push(`${x} ${cursorY - rowHeight} ${width} ${rowHeight} re S`);
            lines.forEach((line, lineIndex) => {
                const textY = cursorY - 11 - lineIndex * 10;
                commands.push(
                    `BT /${header ? 'F2' : 'F1'} ${fontSize} Tf 1 0 0 1 ${x + 4} ${textY} Tm ${pdfString(line)} Tj ET`,
                );
            });
            x += width;
        });

        cursorY -= rowHeight;
    };

    startPage(true);

    if (spec.rows.length === 0) {
        commands.push('1 1 1 rg');
        commands.push(`${margin} ${cursorY - 18} ${tableWidth} 18 re f`);
        commands.push('0.843 0.910 0.965 RG');
        commands.push(`${margin} ${cursorY - 18} ${tableWidth} 18 re S`);
        commands.push('0.490 0.576 0.659 rg');
        commands.push(
            `BT /F1 ${fontSize} Tf 1 0 0 1 ${margin + 4} ${cursorY - 12} Tm ${pdfString(officialEmptyMessage(spec))} Tj ET`,
        );
        cursorY -= 34;
    } else {
        spec.rows.forEach((row) => {
            if (cursorY < margin + 56) {
                pages.push(commands.join('\n'));
                startPage(false);
            }

            drawRow(spec.columns.map((column) => cellValue(row[column.key])));
        });
    }

    const noteLines = wrapText(note, tableWidth, 8);

    if (cursorY < margin + noteLines.length * 11 + 16) {
        pages.push(commands.join('\n'));
        startPage(false);
    }

    cursorY -= 16;
    commands.push('0.490 0.576 0.659 rg');
    noteLines.forEach((line) => {
        commands.push(`BT /F1 8 Tf 1 0 0 1 ${margin} ${cursorY} Tm ${pdfString(line)} Tj ET`);
        cursorY -= 11;
    });
    pages.push(commands.join('\n'));

    const objects: Array<string | Uint8Array> = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];

    if (logo) {
        objects.push(
            concatBytes([
                latin1Bytes(
                    `<< /Type /XObject /Subtype /Image /Width ${logo.width} /Height ${logo.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${logo.bytes.length} >>\nstream\n`,
                ),
                logo.bytes,
                latin1Bytes('\nendstream'),
            ]),
        );
    }

    const imageObject = logo ? 5 : null;
    const pageObjectNumbers: number[] = [];

    pages.forEach((content) => {
        const pageNumber = objects.length + 1;
        const contentNumber = objects.length + 2;
        pageObjectNumbers.push(pageNumber);
        const xObject = imageObject ? ` /XObject << /Im1 ${imageObject} 0 R >>` : '';
        const stream = `${content}\n`;
        objects.push(
            `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>${xObject} >> /Contents ${contentNumber} 0 R >>`,
        );
        objects.push(`<< /Length ${latin1Bytes(stream).length} >>\nstream\n${stream}endstream`);
    });

    objects[1] = `<< /Type /Pages /Kids [${pageObjectNumbers.map((number) => `${number} 0 R`).join(' ')}] /Count ${pages.length} >>`;

    const chunks: Uint8Array[] = [];
    const offsets = [0];
    let position = 0;
    const header = '%PDF-1.4\n';
    chunks.push(latin1Bytes(header));
    position += header.length;

    objects.forEach((body, index) => {
        const object =
            typeof body === 'string'
                ? latin1Bytes(`${index + 1} 0 obj\n${body}\nendobj\n`)
                : concatBytes([
                    latin1Bytes(`${index + 1} 0 obj\n`),
                    body,
                    latin1Bytes('\nendobj\n'),
                ]);
        offsets.push(position);
        chunks.push(object);
        position += object.length;
    });

    const xrefStart = position;
    let xref = `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;

    for (let index = 1; index <= objects.length; index += 1) {
        xref += `${String(offsets[index]).padStart(10, '0')} 00000 n \n`;
    }

    const trailer = `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF\n`;
    chunks.push(latin1Bytes(xref), latin1Bytes(trailer));

    return concatBytes(chunks);
}

export function downloadReportCsv(spec: ReportExportSpec) {
    const lines = [
        [spec.title],
        [spec.subtitle],
        [],
        spec.columns.map((column) => column.header),
        ...spec.rows.map((row) => spec.columns.map((column) => row[column.key] ?? '')),
    ];

    const csv = lines
        .map((line) => line.map((cell) => csvCell(cell)).join(','))
        .join('\r\n');

    triggerDownload(
        new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' }),
        `${spec.filename}.csv`,
    );
}

export function downloadReportExcel(spec: ReportExportSpec) {
    const html = `<html>
<head><meta charset="utf-8"></head>
<body>
    <h1>${escapeHtml(spec.title)}</h1>
    <p>${escapeHtml(spec.subtitle)}</p>
    ${tableHtml(spec)}
</body>
</html>`;

    triggerDownload(
        new Blob(['\uFEFF' + html], { type: 'application/vnd.ms-excel' }),
        `${spec.filename}.xls`,
    );
}

export async function downloadReportPdf(spec: ReportExportSpec) {
    const bytes = await buildPdf(spec);

    triggerDownload(new Blob([bytes as BlobPart], { type: 'application/pdf' }), `${spec.filename}.pdf`);
}

/** Save raw PDF bytes as a downloaded file (e.g. rental slip). */
export function downloadPdfBytes(bytes: Uint8Array, filename: string): void {
    const name = filename.endsWith('.pdf') ? filename : `${filename}.pdf`;
    triggerDownload(new Blob([bytes as BlobPart], { type: 'application/pdf' }), name);
}

export function printReport(spec: ReportExportSpec) {
    openReportWindow(printableHtml(spec));
}

export function downloadReport(format: ReportDownloadFormat, spec: ReportExportSpec) {
    if (format === 'csv') {
        downloadReportCsv(spec);
        return;
    }

    if (format === 'excel') {
        downloadReportExcel(spec);
        return;
    }

    if (format === 'print') {
        printReport(spec);
        return;
    }

    void downloadReportPdf(spec);
}
