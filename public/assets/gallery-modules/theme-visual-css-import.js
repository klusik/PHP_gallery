/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-visual-css-import.js
 * Module Type: Browser Module
 * Purpose: Detect CSS imports before the visual editor opens an isolated preview.
 * Responsibilities: Ignore comments and strings while decoding CSS at-keyword escapes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Return whether CSS contains an actual @import at-keyword.
 * @param {string} source CSS source whose bytes remain owned by the caller.
 * @returns {boolean} True when a case-insensitive or CSS-escaped import at-keyword is present.
 */
export function hasVisualCssImport(source) {
    let index = 0;
    let braceDepth = 0;
    let parenthesisDepth = 0;
    while (index < source.length) {
        const current = source[index];
        const next = source[index + 1];
        if (current === '/' && next === '*') {
            const commentEnd = source.indexOf('*/', index + 2);
            index = commentEnd < 0 ? source.length : commentEnd + 2;
            continue;
        }
        if (current === '"' || current === "'") {
            const quote = current;
            index += 1;
            while (index < source.length) {
                if (source[index] === '\\') {
                    index += 2;
                    continue;
                }
                if (source[index] === quote) {
                    index += 1;
                    break;
                }
                index += 1;
            }
            continue;
        }
        if (current === '\\') {
            index = Math.max(index + 1, decodeVisualCssIdentifier(source, index).end);
            continue;
        }
        if (current === '@' && braceDepth === 0 && parenthesisDepth === 0
            && decodeVisualCssIdentifier(source, index + 1).value.toLowerCase() === 'import') {
            return true;
        }
        if (current === '{') {
            braceDepth += 1;
        } else if (current === '}') {
            braceDepth = Math.max(0, braceDepth - 1);
        } else if (current === '(') {
            parenthesisDepth += 1;
        } else if (current === ')') {
            parenthesisDepth = Math.max(0, parenthesisDepth - 1);
        }
        index += 1;
    }
    return false;
}

/**
 * Decode one CSS identifier beginning at the supplied source offset.
 * @param {string} source CSS source containing the identifier.
 * @param {number} offset UTF-16 offset immediately after an at-sign.
 * @returns {{value: string, end: number}} Decoded identifier and the first source offset after it.
 */
function decodeVisualCssIdentifier(source, offset) {
    let value = '';
    let index = offset;
    while (index < source.length) {
        const character = source[index];
        if (character === '\\') {
            index += 1;
            if (index >= source.length) {
                break;
            }
            if (/[0-9a-f]/i.test(source[index])) {
                const escapeStart = index;
                while (index < source.length && index - escapeStart < 6 && /[0-9a-f]/i.test(source[index])) {
                    index += 1;
                }
                const codePoint = Number.parseInt(source.slice(escapeStart, index), 16);
                value += codePoint > 0 && codePoint < 128 ? String.fromCodePoint(codePoint) : '\u0000';
                if (index < source.length && /[\t\n\r\f ]/.test(source[index])) {
                    if (source[index] === '\r' && source[index + 1] === '\n') {
                        index += 2;
                    } else {
                        index += 1;
                    }
                }
                continue;
            }
            if (source[index] === '\n' || source[index] === '\r' || source[index] === '\f') {
                return { value: '', end: index + 1 };
            }
            value += source[index];
            index += 1;
            continue;
        }
        if (!/[a-z0-9_-]/i.test(character) && character.charCodeAt(0) < 128) {
            break;
        }
        value += character;
        index += 1;
    }
    return { value, end: index };
}
