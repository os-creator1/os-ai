// Contract 17B — money for the product wizard. Pure functions.
//
// The API takes and returns decimal strings in the document currency
// ("250.00"); the wizard works in whole smallest-currency-unit integers internally ONLY to do
// exact arithmetic, and never shows them: everything displayed goes through
// Intl.NumberFormat with the document's currency.

/** Digits after the decimal point for a currency (JPY 0, USD 2, KWD 3). */
export function currencyExponent(currency, locale) {
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits;
    } catch (e) {
        return 2;
    }
}

/** "1250.5" -> 125050 (for exponent 2). null when it is not a plain amount. */
export function toMinor(input, exponent) {
    const text = String(input === undefined || input === null ? '' : input).trim().replace(/,/g, '');
    if (text === '' || !/^\d+(\.\d+)?$/.test(text)) {
        return null;
    }
    const [whole, fraction = ''] = text.split('.');
    if (fraction.length > exponent) {
        // 250.005 is not an amount this currency can hold.
        if (/[1-9]/.test(fraction.slice(exponent))) {
            return null;
        }
    }
    const padded = (fraction + '0'.repeat(exponent)).slice(0, exponent);
    const minor = Number(whole) * Math.pow(10, exponent) + (padded === '' ? 0 : Number(padded));

    return Number.isSafeInteger(minor) ? minor : null;
}

/** 25000 -> "250.00" — what the API receives. */
export function toDecimalString(minor, exponent) {
    const negative = minor < 0;
    const abs = Math.abs(Math.round(minor));
    const scale = Math.pow(10, exponent);
    const whole = Math.floor(abs / scale);
    const fraction = String(abs % scale).padStart(exponent, '0');

    return (negative ? '-' : '') + whole + (exponent > 0 ? '.' + fraction : '');
}

export function formatMinor(minor, currency, locale) {
    if (minor === null || minor === undefined) {
        return '';
    }
    const exponent = currencyExponent(currency, locale);
    try {
        // currencyDisplay 'code' matches the server's own wording ("USD 3,000.00"), so the wizard and the canvas read the same.
        return new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'code' }).format(minor / Math.pow(10, exponent)).replace(/ /g, ' ');
    } catch (e) {
        return (minor / Math.pow(10, exponent)).toFixed(exponent) + ' ' + currency;
    }
}

/**
 * The deposit/balance rules, live. Returns the remaining balance and the
 * message to show (null when the split is valid).
 */
export function depositState(totalMinor, depositInput, exponent) {
    const text = String(depositInput === undefined || depositInput === null ? '' : depositInput).trim();

    if (text === '') {
        return { depositMinor: null, balanceMinor: null, error: 'Enter the deposit amount.' };
    }

    const depositMinor = toMinor(text, exponent);
    if (depositMinor === null) {
        return { depositMinor: null, balanceMinor: null, error: 'Enter the deposit as an amount, for example 250.00.' };
    }
    if (depositMinor <= 0) {
        return { depositMinor, balanceMinor: totalMinor - depositMinor, error: 'The deposit must be more than zero.' };
    }

    const balanceMinor = totalMinor - depositMinor;
    if (balanceMinor <= 0) {
        return { depositMinor, balanceMinor, error: 'The deposit must be less than the total, so there is a balance left to pay.' };
    }

    return { depositMinor, balanceMinor, error: null };
}

/** "2027-03-12" -> "12 Mar 2027" (calendar date, no timezone shift). */
export function formatDate(iso, locale) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!match) {
        return '';
    }
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    try {
        return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date);
    } catch (e) {
        return iso;
    }
}
