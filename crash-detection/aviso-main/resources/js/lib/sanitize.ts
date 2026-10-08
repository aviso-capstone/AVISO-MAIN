/**
 * Sanitizes generic text input by stripping potentially dangerous HTML characters.
 * Useful for fields like names, addresses, or general text where some special characters are allowed but scripts are not.
 */
export function sanitizeText(input: string): string {
    if (!input) return "";
    let sanitized = input.trim();
    // Remove anything that looks like an HTML tag to mirror backend strip_tags
    sanitized = sanitized.replace(/<[^>]*>?/gm, '');
    return sanitized;
}

/**
 * Strictly sanitizes input to only allow alphanumeric characters.
 * Useful for usernames, codes, or specific identifiers.
 */
export function sanitizeAlphanumeric(input: string): string {
    if (!input) return "";
    let sanitized = input.trimStart();
    return sanitized.replace(/[^a-zA-Z0-9]/g, "");
}
