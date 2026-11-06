/**
 * Input limits and live filters for typed fields. The numbers match
 * app/Support/InputRules.php on the server, which has the final say; these only
 * stop the admin from typing something the server would refuse.
 */
export const LIMITS = {
    name: 50,
    username: 30,
    email: 100,
    phone: 16, // "+63 917 123 4567"
    relationship: 30,
    street: 150,
    search: 100,
    password: 64,
    login: 100,
    otp: 6,
} as const;

/**
 * Sanitizes generic text input by stripping potentially dangerous HTML characters.
 * Useful for fields like addresses or general text where some special characters are allowed but scripts are not.
 */
export function sanitizeText(input: string): string {
    if (!input) return "";
    let sanitized = input.trim();
    // Remove anything that looks like an HTML tag to mirror backend strip_tags
    sanitized = sanitized.replace(/<[^>]*>?/gm, '');
    return sanitized;
}

/** A person's name while typing: letters (any language), spaces, period, apostrophe, hyphen. */
export function sanitizeName(input: string): string {
    if (!input) return "";
    return input.replace(/[^\p{L}\s.'-]/gu, '').replace(/\s{2,}/g, ' ').trimStart().slice(0, LIMITS.name);
}

/** An emergency contact's name: like a person's name, plus numbers and brackets ("Kuya Ben (Work)"). */
export function sanitizeContactName(input: string): string {
    if (!input) return "";
    return input.replace(/[^\p{L}\p{N}\s.'()-]/gu, '').replace(/\s{2,}/g, ' ').trimStart().slice(0, LIMITS.name);
}

/** A relationship ("Mother", "Brother-in-law"): letters, spaces, apostrophe, hyphen. */
export function sanitizeRelationship(input: string): string {
    if (!input) return "";
    return input.replace(/[^\p{L}\s'-]/gu, '').replace(/\s{2,}/g, ' ').trimStart().slice(0, LIMITS.relationship);
}

/** A username: letters, numbers and underscores. */
export function sanitizeUsername(input: string): string {
    if (!input) return "";
    return input.replace(/[^A-Za-z0-9_]/g, '').slice(0, LIMITS.username);
}

/**
 * A Philippine mobile number while typing: digits, a leading +, spaces and
 * hyphens. The server turns 0917…, 917… and +63917… into +639171234567.
 */
export function sanitizePhone(input: string): string {
    if (!input) return "";
    const kept = input.replace(/[^\d+\s-]/g, '');
    const plus = kept.trimStart().startsWith('+') ? '+' : '';
    return (plus + kept.replace(/\+/g, '')).trimStart().slice(0, LIMITS.phone);
}

/** Digits only, for the 6-digit code. */
export function sanitizeDigits(input: string, max: number = LIMITS.otp): string {
    if (!input) return "";
    return input.replace(/\D/g, '').slice(0, max);
}

/**
 * Strictly sanitizes input to only allow alphanumeric characters.
 * Useful for codes or specific identifiers.
 */
export function sanitizeAlphanumeric(input: string): string {
    if (!input) return "";
    let sanitized = input.trimStart();
    return sanitized.replace(/[^a-zA-Z0-9]/g, "");
}
