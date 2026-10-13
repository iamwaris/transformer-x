// Plain-language names and fixes for every error / warning code the server can report.

export const ISSUE_LABELS = {
  // file-level
  not_xlsx: ['Not an .xlsx workbook', 'Save the file from Excel as "Excel Workbook (*.xlsx)".'],
  macro_workbook: ['Workbook contains macros', 'Save it as a plain .xlsx file (not .xlsm).'],
  too_large: ['Workbook too large', 'Remove extra sheets or split the products into several files.'],
  file_unreadable: ['File could not be read', 'Open it in Excel, save it again as .xlsx, and re-upload.'],
  empty_sheet: ['First sheet is empty', 'Put the headers in row 1 of the first sheet.'],
  no_rows: ['No product rows', 'Add products below the header row.'],
  too_many_rows: ['Too many rows', 'Split the products into smaller files.'],
  missing_column: ['Missing column', 'Add the column to row 1. It may stay empty.'],
  misspelled_column: ['Column name spelled differently', 'Rename the header to the exact name shown.'],
  unknown_column: ['Column not in the format', 'Delete the column from the sheet.'],
  duplicate_column: ['Column appears twice', 'Keep one copy of the column.'],
  // row-level errors
  unheaded_value: ['Value in a column without a header', 'Clear the cell or delete that column.'],
  text_in_number_column: ['Text in a number column', 'Make the cell a number (no letters, currency signs or spaces).'],
  not_a_number: ['Invalid number', 'Enter a normal number.'],
  text_too_long: ['Text longer than 255 characters', 'Shorten the text.'],
  date_value: ['Date in a cell', 'Format the cell as number or text instead of a date.'],
  boolean_value: ['TRUE/FALSE in a cell', 'Enter a number or text instead.'],
  blank_reference: ['Internal Reference is empty', 'Give every product a unique Internal Reference.'],
  duplicate_reference: ['Internal Reference used more than once', 'Make each Internal Reference unique.'],
  // warnings
  zero_price: ['Regular price is $0.00', 'Usually Average Weight (parcels) or Forecasted Quantity is 0. Check the source data.'],
  blank_price: ['Regular price will be empty', 'A value needed for the price is missing.'],
  negative_price: ['Regular price is negative', 'Usually a negative Forecasted Quantity.'],
  price_times_quantity: ['Piece price multiplied by quantity', 'Sold per piece but Forecasted Quantity is not 1, so the price is multiplied (same as the Access tool).'],
  attributes_dropped: ['More than 18 attributes', 'Only the first 18 filled-in fields become attributes.'],
  unknown_category: ['Unrecognised Product Category', 'Only Gemstones, Diamonds and Jewellery get sub-categories.'],
  no_category: ['No Product Category', 'The product gets no category.'],
};

export function issueLabel(code) {
  return (ISSUE_LABELS[code] || [code.replace(/_/g, ' '), ''])[0];
}

export function issueFix(code) {
  return (ISSUE_LABELS[code] || ['', ''])[1];
}
