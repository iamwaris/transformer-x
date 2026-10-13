import { api, download } from '../api.js';
import { h, toast, icon } from '../dom.js';
import { ISSUE_LABELS } from '../labels.js';

const COLUMN_NOTES = {
  'Product Category': 'Gemstones, Diamonds or Jewellery decide the sub-categories.',
  Name: 'Product name.',
  'Internal Reference': 'Required and unique. Becomes the SKU.',
  'Product Description (Copy)': 'Becomes the Short description.',
  Length: 'Copied; used in the Size attribute.',
  Width: 'Copied; used in the Size attribute.',
  Depth: 'Becomes Height; used in the Size attribute.',
  'Product Type': '"Parcel" changes the price formula. Also a category for Diamonds and Jewellery.',
  'Sales Price': 'Used in the Regular price.',
  'Average Weight': 'Used in the Regular price of parcels.',
  'Forecasted Quantity': 'Becomes Stock; used in the Regular price of non-parcels.',
  Species: 'Gemstone category and the "Gemstone" attribute.',
  'Cutting Style': 'Gemstone category and an attribute.',
  'DIAMOND Shape': 'Diamond category.',
  'Colour 1': 'Gemstone colour category.', 'Colour 2': 'Gemstone colour category.', 'Colour 3': 'Gemstone colour category.',
  Unit: 'Copied as is (Carats or Piece).',
  Shape: 'Copied as is.',
  'Market Term': 'Read but not used in the output.',
};

const OUTPUT_MAP = [
  ['Type', '"Simple" for every product'],
  ['SKU', 'Internal Reference'],
  ['Name', 'Name'],
  ['Published', '"1"'],
  ['Is featured?', '0'],
  ['visibility in catalog', '"visible"'],
  ['Short description', 'Product Description (Copy)'],
  ['Length, Width', 'Length, Width'],
  ['Height', 'Depth'],
  ['Allow customer reviews?', '0'],
  ['Regular price', 'Calculated (see below)'],
  ['Categories', 'Calculated (see below)'],
  ['Colour 1–3, Cutting Style, Shape, DIAMOND Shape, Product Type, Species', 'Copied from the same columns'],
  ['Stock', 'Forecasted Quantity'],
  ['Attribute 1–18 name / value(s) / visible / global', 'Calculated (see below); visible and global are always 1'],
  ['ID, GTIN, Purchase note, Sale price, Tags, Shipping Class, Images, Download limit, Download expiry days, Parent, Grouped products, Upsells, Cross-sells, External URL, Button text, Position, Brands', 'Always empty'],
];

export async function renderHelp(main) {
  const format = await api('/format');
  const numeric = new Set(format.numericColumns);
  const toc = [['start', 'How it works'], ['format', 'Upload format'], ['output', 'Output file'], ['price', 'Regular price'],
    ['categories', 'Categories'], ['attributes', 'Attributes'], ['checks', 'Checks'], ['privacy', 'Privacy'], ['faq', 'Questions']];

  const templateBtn = h('button', { class: 'btn', type: 'button', onclick: async () => {
    try { await download('/template'); } catch (err) { toast(err.message, 'bad'); }
  } }, icon('download'), 'Download blank template');

  const sec = (id, title, ...content) => h('section', { class: 'panel', id: `help-${id}` }, h('div', { class: 'prose' }, h('h2', { text: title }), ...content));

  main.append(
    h('div', { class: 'page-head' }, h('div', {}, h('h1', { text: 'Help guide' }),
      h('p', { text: 'Transformer X turns the product upload sheet into a WooCommerce product import file, with exactly the same results as the old Access tool (DataTransformer v3.5).' })),
    h('div', { class: 'actions' }, templateBtn)),
    h('nav', { class: 'toc', 'aria-label': 'Help sections' }, toc.map(([id, label]) => h('a', { class: 'btn small', href: `#/help`, onclick: (e) => {
      e.preventDefault(); document.getElementById(`help-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } }, label))),

    sec('start', 'How it works',
      h('ol', {},
        h('li', {}, h('b', { text: 'Upload. ' }), 'Choose New conversion and drop your .xlsx file. Only the first sheet is read.'),
        h('li', {}, h('b', { text: 'Review the preview. ' }), 'Every row is checked and converted on screen. Errors must be fixed before converting; warnings are for your information. Click any row to see how its price, categories and attributes were worked out.'),
        h('li', {}, h('b', { text: 'Convert. ' }), 'Choose Convert and download. The WooCommerce file (named like 20261013_143005.xlsx) downloads straight away.'),
        h('li', {}, h('b', { text: 'Import into WooCommerce. ' }), 'In WordPress go to Products › Import and upload the downloaded file.'))),

    sec('format', 'Upload format',
      h('p', { text: `An Excel .xlsx workbook. Row 1 holds the ${format.inputColumns.length} headers below, spelled exactly (capitals and spaces matter); their order does not. Each further row is one product. Fully empty rows are skipped. Limits: ${format.maxUploadMb} MB and ${format.maxRows.toLocaleString('en-US')} products per file, 255 characters per text cell.` }),
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Column' }), h('th', { text: 'Type' }), h('th', { text: 'Used for' }))),
        h('tbody', {}, format.inputColumns.map((c) => h('tr', {},
          h('td', { class: 'nowrap', text: c }), h('td', { text: numeric.has(c) ? 'Number' : 'Text' }),
          h('td', { text: COLUMN_NOTES[c] || (format.attributeFields.includes(c) ? 'Becomes an attribute when filled in.' : 'Copied as is.') })))))),
      h('p', { text: 'A number typed into a text column is accepted and converted to text the way Access does (for example 18 → "18", 1234567890123 → "1.2345678901e+12"). Text in a number column is an error.' })),

    sec('output', 'The output file',
      h('p', { text: `One sheet named "qryExportData" with ${format.outputColumns.length} columns in WooCommerce order. Text stays text, numbers stay numbers, and trailing spaces are removed from text cells.` }),
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Output column' }), h('th', { text: 'Comes from' }))),
        h('tbody', {}, OUTPUT_MAP.map(([o, src]) => h('tr', {}, h('td', { class: 'wrap', text: o }), h('td', { class: 'wrap', text: src }))))))),

    sec('price', 'How Regular price is calculated',
      h('ul', {},
        h('li', {}, 'If Product Type is ', h('b', { text: 'Parcel' }), ': ', h('code', { text: 'Sales Price × Average Weight' }), '.'),
        h('li', {}, 'Otherwise: ', h('code', { text: 'Sales Price × Forecasted Quantity' }), '.'),
        h('li', { text: '"Parcel" is matched ignoring capitals and trailing spaces ("parcel", "PARCEL " count), but not leading spaces.' }),
        h('li', { text: 'The result is rounded to cents, halves rounded up, and written as text in US dollar format: $1,195.00. Negative values appear in brackets: ($5.00).' }),
        h('li', { text: 'If a value the formula needs is empty, Regular price is left empty.' })),
      h('div', { class: 'calc' },
        h('div', { text: 'Parcel:      1195 × 0.00625 = 7.46875   → $7.47' }),
        h('div', { text: 'Single stone: 1499 × 2.35    = 3522.65   → $3,522.65' }),
        h('div', { text: 'Parcel with Average Weight 0: 11 × 0 = 0 → $0.00 (warning)' })),
      h('p', { class: 'muted', text: 'These are the Access tool\'s rules, kept unchanged. Parcels sold per piece often have an Average Weight of 0 and so get $0.00; the preview flags every such row.' })),

    sec('categories', 'How Categories are built',
      h('p', { text: 'The Product Category itself is always the first category. Then, depending on it:' }),
      h('ul', {},
        h('li', {}, h('b', { text: 'Gemstones: ' }), 'Gemstones > Colour > (each of Colour 1–3), Gemstones > Species > (Species), Gemstones > Cutting Style > (Cutting Style).'),
        h('li', {}, h('b', { text: 'Diamonds: ' }), 'Diamonds > (DIAMOND Shape), Diamonds > (Product Type).'),
        h('li', {}, h('b', { text: 'Jewellery: ' }), 'Jewellery > (Product Type).'),
        h('li', { text: 'Any other category: only the category itself.' }),
        h('li', { text: 'Empty values are skipped and exact duplicates are listed once. Categories are separated by ", ".' })),
      h('div', { class: 'calc' }, h('div', { text: 'Gemstones, Gemstones > Colour > Green, Gemstones > Species > Emerald, Gemstones > Cutting Style > Step Cut' }))),

    sec('attributes', 'How Attributes are filled',
      h('p', { text: 'These fields are checked in this order. Each one that has a value takes the next free attribute slot (name, value, visible = 1, global = 1). Values are trimmed of spaces.' }),
      h('ol', {}, format.attributeFields.map((f) => h('li', {}, f === 'Species' ? 'Species — named "Gemstone"' : f === 'Size' ? 'Size — always filled: Length x Width x Depth mm, e.g. "0.8x0.8x0 mm"' : f))),
      h('p', { text: 'There are 18 slots. If more fields are filled in, the later ones are left out and the preview warns you.' })),

    sec('checks', 'What is checked',
      h('p', {}, h('b', { text: 'Errors' }), ' stop the conversion until the sheet is fixed and uploaded again. ', h('b', { text: 'Warnings' }), ' are converted exactly as the Access tool would, but deserve a look.'),
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', {}, h('tr', {}, h('th', { text: 'Message' }), h('th', { text: 'Kind' }), h('th', { text: 'What to do' }))),
        h('tbody', {}, Object.entries(ISSUE_LABELS).map(([code, [label, fix]]) => h('tr', {},
          h('td', { text: label }),
          h('td', {}, h('span', { class: `pill ${['zero_price', 'blank_price', 'negative_price', 'price_times_quantity', 'attributes_dropped', 'unknown_category', 'no_category'].includes(code) ? 'warn' : 'bad'}`,
            text: ['zero_price', 'blank_price', 'negative_price', 'price_times_quantity', 'attributes_dropped', 'unknown_category', 'no_category'].includes(code) ? 'Warning' : 'Error' })),
          h('td', { text: fix }))))))),

    sec('privacy', 'Your files and privacy',
      h('ul', {},
        h('li', { text: 'The uploaded file is deleted as soon as it has been read.' }),
        h('li', { text: `The rows shown in the preview are kept only until you convert or discard, or for ${format.previewExpiryHours} hours at most.` }),
        h('li', { text: 'The converted file is created, sent to your browser and deleted. It cannot be downloaded again later.' }),
        h('li', { text: 'What is kept: the file name, size and fingerprint (SHA-256), counts, totals and warnings — the statistics on the History and Statistics pages.' }))),

    sec('faq', 'Questions',
      faq('Why do many products show $0.00?', 'For parcels the price is Sales Price × Average Weight. When Average Weight is 0 (common for parcels priced per piece), the result is $0.00. Fix the Average Weight in the source data and upload again.'),
      faq('Can I download a converted file again?', 'No. Files are not stored. Upload the same sheet again and convert it; you get an identical file.'),
      faq('Does the column order matter?', 'No. Only the header names must match exactly.'),
      faq('A column is reported as spelled differently, but it looks right.', 'Look for extra spaces at the start or end of the header, or different capital letters. Copy the header from the blank template.'),
      faq('I was signed out.', 'For security you are signed out after a period without activity, and after 8 hours in any case. Sign in again; open previews are kept.'),
      faq('I forgot my password or my account is locked.', 'Ask an administrator. They can unlock the account or give you a temporary password.'),
      faq('Is the result really identical to the Access tool?', 'Yes. The converter was checked cell by cell against files produced by the Access application, including rounding, number formatting, spaces and capital letters.')),
  );
}

function faq(q, a) {
  return h('details', { class: 'faq' }, h('summary', { text: q }), h('div', {}, h('p', { text: a })));
}
