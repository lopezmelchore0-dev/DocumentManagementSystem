/**
 * Regenerates the text-page fixtures in tests/scanner/fixtures/ with real fonts (headless Chrome).
 *   npm run fixtures
 * text-page.png      upright A4-proportioned page, 1000 px wide, English + Filipino text
 * text-skew3.png     the same page turned 3° clockwise
 * text-rot90.png     turned 90° clockwise (reads top to bottom)
 * text-rot270.png    turned 90° counter-clockwise
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { withBrowser } = require('./cdp');

const OUT = path.join(__dirname, 'fixtures');

const RENDER = `(() => {
  const W = 1000, H = 1414;
  const lines = [
    'REPUBLIC OF THE PHILIPPINES', 'SANGGUNIANG BAYAN', '',
    'RESOLUTION NO. 2026-117', '',
    'A RESOLUTION AUTHORIZING THE MUNICIPAL MAYOR TO ENTER INTO A MEMORANDUM OF',
    'AGREEMENT WITH THE PROVINCIAL GOVERNMENT FOR THE IMPROVEMENT OF FARM-TO-MARKET',
    'ROADS IN BARANGAY SAN JOSE AND BARANGAY MALIGAYA, AND FOR OTHER PURPOSES.', '',
    'WHEREAS, the Local Government Code of 1991 empowers the Sangguniang Bayan to',
    'enact ordinances and approve resolutions for the general welfare of the',
    'municipality and its inhabitants, and to provide for the efficient delivery of',
    'basic services and facilities, including roads, bridges and public buildings;', '',
    'WHEREAS, ang mga mamamayan ng Barangay San Jose ay humiling ng agarang pagkukumpuni',
    'ng daan patungo sa pamilihan upang mapabilis ang paghahatid ng kanilang ani at',
    'produkto, lalo na sa panahon ng tag-ulan kung kailan halos hindi madaanan ang kalsada;', '',
    'WHEREAS, the Provincial Government has allotted funds for this purpose under the',
    'Annual Investment Program, subject to a counterpart of twenty percent (20%) from',
    'the municipality, which shall be charged against the Twenty Percent Development Fund;', '',
    'NOW, THEREFORE, on motion of Hon. Juan dela Cruz, duly seconded by Hon. Maria Santos,',
    'be it RESOLVED, as it is hereby RESOLVED, to authorize the Municipal Mayor to enter',
    'into and sign the Memorandum of Agreement, a copy of which is attached hereto;', '',
    'RESOLVED FURTHER, that copies of this resolution be furnished the Provincial Governor,',
    'the Provincial Engineering Office, and all concerned offices for their information',
    'and appropriate action.', '',
    'UNANIMOUSLY APPROVED this 18th day of November, 2026.', '', '',
    'I HEREBY CERTIFY to the correctness of the foregoing resolution.', '', '',
    'ANA REYES', 'Secretary to the Sangguniang Bayan', '',
    'ATTESTED:', '', 'HON. PEDRO GARCIA', 'Vice Mayor / Presiding Officer'
  ];
  function page() {
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    const x = c.getContext('2d');
    x.fillStyle = '#f6f4ef'; x.fillRect(0, 0, W, H);
    x.fillStyle = '#1e1e1e';
    let y = 110;
    lines.forEach((t, i) => {
      x.font = (i < 4 ? 'bold 15px' : '13.5px') + ' Arial, Helvetica, sans-serif';
      if (i < 4) { x.textAlign = 'center'; x.fillText(t, W / 2, y); x.textAlign = 'left'; }
      else { x.fillText(t, 80, y); }
      y += 24;
    });
    return c;
  }
  function turned(src, deg) {
    const swap = deg === 90 || deg === 270;
    const c = document.createElement('canvas');
    c.width = swap ? src.height : src.width; c.height = swap ? src.width : src.height;
    const x = c.getContext('2d');
    x.fillStyle = '#f6f4ef'; x.fillRect(0, 0, c.width, c.height);
    x.translate(c.width / 2, c.height / 2); x.rotate(deg * Math.PI / 180); x.drawImage(src, -src.width / 2, -src.height / 2);
    return c;
  }
  const p = page();
  return {
    page: p.toDataURL('image/png'),
    skew3: turned(p, 3).toDataURL('image/png'),
    rot90: turned(p, 90).toDataURL('image/png'),
    rot270: turned(p, 270).toDataURL('image/png')
  };
})()`;

withBrowser(async (page) => {
    const r = await page.eval(RENDER);
    fs.mkdirSync(OUT, { recursive: true });
    const save = (name, url) => fs.writeFileSync(path.join(OUT, name), Buffer.from(url.split(',')[1], 'base64'));
    save('text-page.png', r.page);
    save('text-skew3.png', r.skew3);
    save('text-rot90.png', r.rot90);
    save('text-rot270.png', r.rot270);
    console.log('Fixtures written to ' + OUT);
}).catch((e) => { console.error(e); process.exit(1); });
