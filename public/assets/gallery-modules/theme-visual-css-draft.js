/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-visual-css-draft.js
 * Module Type: Browser Module
 * Purpose: Maintain explicit, safe visual CSS edits without rewriting user-authored stylesheet bytes.
 * Responsibilities: Parse one owned block, validate authored declarations, serialize immutable snapshots, and support undo/redo.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * One explicit CSS declaration managed by the visual editor.
 * @typedef {{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:string,value:string,companionOf?:{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:'background-color'}}} VisualCssDraftRule
 */

/**
 * Source boundaries and line policy for the one managed CSS block.
 * @typedef {{prefix:string,suffix:string,separator:'none'|'lf'|'crlf',lineEnding:'\n'|'\r\n'}} VisualCssDraftBlock
 */

/**
 * Immutable model for one CSS text draft.
 * @typedef {{css:string,sourceCss:string,rules:ReadonlyArray<VisualCssDraftRule>,warning:string|null,valid:boolean,history:ReadonlyArray<ReadonlyArray<VisualCssDraftRule>>,historyIndex:number,baseCss:string,block:VisualCssDraftBlock|null,hasManagedBlock:boolean}} VisualCssDraftModel
 */

/**
 * Bound selector bytes admitted to the managed block.
 * Type: number.
 * Units: UTF-16 code units. Scope: one explicitly authored visual CSS selector.
 * Consumers: `visualCssDraftSelectorIsValid` before a selector enters model state.
 * Rationale: generated selectors are short element paths; longer arbitrary selectors add risk without helping visual targeting.
 */
const VISUAL_CSS_DRAFT_SELECTOR_MAX_LENGTH = 512;

/**
 * Bound declaration value bytes admitted to the managed block.
 * Type: number.
 * Units: UTF-16 code units. Scope: one explicitly authored visual CSS property value.
 * Consumers: `visualCssDraftPropertyValueIsValid` before a value enters model state.
 * Rationale: property controls accept concise literal CSS tokens and never need stylesheet-sized values.
 */
const VISUAL_CSS_DRAFT_VALUE_MAX_LENGTH = 256;

/**
 * Bound the number of backdrop-filter functions accepted in one managed value.
 * Type: number.
 * Units: filter functions.
 * Scope: one `backdrop-filter` or `-webkit-backdrop-filter` declaration.
 * Consumers: `visualCssDraftPropertyValueIsValid` while parsing filter values.
 * Rationale: the Theme backdrop uses one blur and one saturation function; other function shapes remain outside this editor grammar.
 */
const VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_FUNCTIONS = 2;

/**
 * Bound the literal blur radius accepted by the Theme visual editor.
 * Type: number.
 * Units: CSS pixels.
 * Scope: one `blur()` function in a managed backdrop filter.
 * Consumers: `visualCssDraftPropertyValueIsValid` for both standard and prefixed properties.
 * Rationale: the Theme currently uses 10px and 12px blur values; 32px bounds explicit overrides while preserving those defaults.
 */
const VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_BLUR_PX = 32;

/**
 * Bound the saturation multiplier accepted by the Theme visual editor.
 * Type: number.
 * Units: multiplier.
 * Scope: one `saturate()` function in a managed backdrop filter.
 * Consumers: `visualCssDraftPropertyValueIsValid` for both standard and prefixed properties.
 * Rationale: the existing Theme saturation values remain editable while authored values stay within a bounded multiplier range.
 */
const VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_SATURATION = 2;

/**
 * Bound data carried in one managed declaration set.
 * Type: number.
 * Units: declaration records. Scope: one visual CSS managed block.
 * Consumers: parser and serializer admission checks.
 * Rationale: the visual editor changes a focused selection, so a bounded set prevents malformed blocks from consuming unbounded work.
 */
const VISUAL_CSS_DRAFT_MAX_RULES = 256;

/**
 * Define the only responsive scopes the visual editor can author.
 * Type: Readonly<Record<string,number>>.
 * Units: CSS pixels. Scope: one stylesheet's explicitly selected responsive override.
 * Consumers: rule validation and managed-block serialization.
 * Rationale: responsive rules are explicit user choices and remain independent from the preview viewport preset.
 */
const VISUAL_CSS_DRAFT_BREAKPOINTS = Object.freeze({tablet: 768, mobile: 480});

/**
 * Closed set of CSS named colors accepted as literal visual-editor values.
 * Type: ReadonlyArray<string>.
 * Units: CSS named-color keywords. Scope: color, background-color, border-color, and box-shadow literals.
 * Consumers: visualCssDraftColorIsValid.
 * Rationale: reject arbitrary identifiers while preserving standard browser color names.
 */
const VISUAL_CSS_DRAFT_NAMED_COLORS = Object.freeze(('aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen transparent currentcolor').split(' '));

/**
 * Define CSS properties controlled by the visual editor and their intended value groups.
 * Type: Readonly<Record<string,string>>.
 * Units: CSS property names and value groups. Scope: visual editor managed declarations.
 * Consumers: property validation, Stage3 property controls, and Stage5 Theme width/background controls.
 * Rationale: a closed property set prevents arbitrary declarations from entering the managed block; Theme width, opacity, fit, position, and backdrop-filter values are restricted to their existing public layer targets.
 */
const VISUAL_CSS_DRAFT_PROPERTY_GROUPS = Object.freeze({
    color: 'color',
    'background-color': 'color',
    'background-image': 'background-image',
    'border-color': 'color-list',
    'font-size': 'font-size',
    'font-weight': 'font-weight',
    'line-height': 'line-height',
    'text-align': 'text-align',
    'text-decoration': 'text-decoration',
    'backdrop-filter': 'backdrop-filter',
    '-webkit-backdrop-filter': 'backdrop-filter',
    'border-radius': 'length-list',
    'border-width': 'border-width',
    'border-style': 'border-style',
    'box-shadow': 'shadow',
    gap: 'gap',
    'min-height': 'size',
    width: 'size',
    height: 'size',
    'object-fit': 'object-fit',
    'object-position': 'position',
    'background-size': 'background-size',
    'background-position': 'background-position',
    padding: 'length-list',
    margin: 'margin-list',
    opacity: 'opacity',
});

/** Identify any occurrence of the managed BEGIN marker, including malformed forms.
 * Type: string. Units: marker-prefix code units. Scope: one submitted stylesheet.
 * Consumers: `countVisualCssDraftMarkers` rejects marker conflicts.
 * Rationale: partial or duplicated ownership markers must retain the user's original CSS.
 */
const VISUAL_CSS_DRAFT_BEGIN_PREFIX = '/* PHP Gallery managed visual CSS BEGIN';
/** Identify any occurrence of the managed DATA marker, including malformed forms.
 * Type: string. Units: marker-prefix code units. Scope: one submitted stylesheet.
 * Consumers: `countVisualCssDraftMarkers` rejects marker conflicts.
 * Rationale: encoded rules are trusted only inside one complete, unique managed block.
 */
const VISUAL_CSS_DRAFT_DATA_PREFIX = '/* PHP Gallery managed visual CSS DATA';
/** Identify any occurrence of the managed END marker, including malformed forms.
 * Type: string. Units: marker-prefix code units. Scope: one submitted stylesheet.
 * Consumers: `countVisualCssDraftMarkers` rejects marker conflicts.
 * Rationale: a missing or duplicated terminator must leave the user's original CSS untouched.
 */
const VISUAL_CSS_DRAFT_END_PREFIX = '/* PHP Gallery managed visual CSS END';
/** Match the one supported managed BEGIN marker line.
 * Type: RegExp. Units: one CSS line. Scope: one submitted stylesheet.
 * Consumers: `parseVisualCssDraft` locates the versioned block boundary and line policy.
 * Rationale: marker metadata is accepted only in the exact serialization format owned by this model.
 */
const VISUAL_CSS_DRAFT_BEGIN_LINE = /^\/\* PHP Gallery managed visual CSS BEGIN v1 separator=(none|lf|crlf) line-ending=(lf|crlf) \*\/\r?$/gm;
/** Match the one Base64 declaration-record line.
 * Type: RegExp. Units: one CSS line. Scope: one submitted stylesheet.
 * Consumers: `parseVisualCssDraft` validates the encoded managed declaration record.
 * Rationale: only canonical bounded data inside the owned block may be reloaded as editor state.
 */
const VISUAL_CSS_DRAFT_DATA_LINE = /^\/\* PHP Gallery managed visual CSS DATA ([A-Za-z0-9+/=]+) \*\/\r?$/gm;
/** Match the one supported managed END marker line.
 * Type: RegExp. Units: one CSS line. Scope: one submitted stylesheet.
 * Consumers: `parseVisualCssDraft` verifies the complete block boundary.
 * Rationale: the model must not claim bytes outside the explicit owned region.
 */
const VISUAL_CSS_DRAFT_END_LINE = /^\/\* PHP Gallery managed visual CSS END v1 \*\/\r?$/gm;

/**
 * Freeze declaration records and their array so callers cannot mutate model history.
 * @param {Array<VisualCssDraftRule>} rules Declarations in stable authoring order.
 * @returns {Array<VisualCssDraftRule>} Frozen declaration snapshot.
 */
function freezeVisualCssDraftRules(rules) {
    return Object.freeze(rules.map(rule => Object.freeze({...rule,
        ...(rule.companionOf ? {companionOf: Object.freeze({...rule.companionOf})} : {}),
    })));
}

/**
 * Freeze a history list containing complete declaration snapshots.
 * @param {Array<Array<VisualCssDraftRule>>} history Candidate undo/redo snapshots.
 * @returns {Array<Array<VisualCssDraftRule>>} Frozen history and frozen snapshots.
 */
function freezeVisualCssDraftHistory(history) {
    return Object.freeze(history.map(freezeVisualCssDraftRules));
}

/**
 * Return one immutable model carrying the supplied CSS and declaration history.
 * @param {string} css Current serialized stylesheet bytes.
 * @param {string} sourceCss Exact stylesheet bytes supplied to the parser.
 * @param {Array<VisualCssDraftRule>} rules Current explicit declarations.
 * @param {string|null} warning Bounded user-facing explanation, or null when the model is valid and unchanged.
 * @param {boolean} valid Whether the source marker block and all declarations passed validation.
 * @param {Array<Array<VisualCssDraftRule>>} history Immutable undo/redo snapshots to freeze.
 * @param {number} historyIndex Index of the snapshot currently represented by `rules`.
 * @param {string} baseCss Exact stylesheet bytes outside the managed block.
 * @param {VisualCssDraftBlock|null} block Source boundaries used to preserve bytes around an existing block.
 * @param {boolean} hasManagedBlock Whether `css` currently contains the owned block.
 * @returns {VisualCssDraftModel} Immutable and internally consistent CSS draft state.
 */
function createVisualCssDraftModel(css, sourceCss, rules, warning, valid, history, historyIndex, baseCss, block, hasManagedBlock) {
    const snapshots = freezeVisualCssDraftHistory(history);
    const frozenRules = snapshots[historyIndex] || freezeVisualCssDraftRules(rules);
    const frozenBlock = block ? Object.freeze({...block}) : null;
    return Object.freeze({
        css,
        sourceCss,
        rules: frozenRules,
        warning,
        valid,
        history: snapshots,
        historyIndex,
        baseCss,
        block: frozenBlock,
        hasManagedBlock,
    });
}

/**
 * Return a regular expression matching a safe visual-editor selector chain.
 * @returns {RegExp} Pattern for tag, class, id, bounded attribute, and structural selector chains before an optional supported pseudo-element suffix.
 */
function visualCssDraftSelectorPattern() {
    const atom = '(?:[A-Za-z][A-Za-z0-9_-]*|\\*|#[A-Za-z_][A-Za-z0-9_-]*|\\.[A-Za-z_][A-Za-z0-9_-]*|\\[[A-Za-z_][A-Za-z0-9_-]*(?:=(?:[A-Za-z0-9_-]+|"[A-Za-z0-9 _-]*"|\'[A-Za-z0-9 _-]*\'))?\\]|:(?:root|first-child|last-child|only-child|first-of-type|last-of-type|nth-child\\([1-9][0-9]*\\)|nth-of-type\\([1-9][0-9]*\\)))';
    return new RegExp('^' + atom + '(?:(?:\\s+|\\s*>\\s*|\\s*\\+\\s*|\\s*~\\s*)' + atom + ')*$');
}

/**
 * Validate one stable selector accepted by the visual editor.
 * @param {string} selector Candidate selector generated or explicitly confirmed by the element inspector, optionally ending in `::before` or `::after`.
 * @returns {{valid:boolean,reason:string}} Validation result with a bounded reason code.
 */
export function visualCssDraftSelectorIsValid(selector) {
    if (typeof selector !== 'string' || selector.length === 0 || selector.length > VISUAL_CSS_DRAFT_SELECTOR_MAX_LENGTH
        || /[\u0000-\u001f\u007f\\{};,@]/.test(selector)) {
        return {valid: false, reason: 'selector_shape'};
    }
    const pseudoElement = selector.match(/^(.*)::(before|after)$/);
    const baseSelector = pseudoElement ? pseudoElement[1] : selector;
    return visualCssDraftSelectorPattern().test(baseSelector) || visualCssDraftCompoundSelectorChainIsValid(baseSelector)
        ? {valid: true, reason: 'ok'}
        : {valid: false, reason: 'selector_shape'};
}

/**
 * Validate generated compound selectors such as article.card and their safe chains.
 * @param {string} selector Candidate selector already checked for control and escape characters.
 * @returns {boolean} True when every compound consists only of supported simple selector atoms.
 */
function visualCssDraftCompoundSelectorChainIsValid(selector) {
    if (/^\s*[>+~]|[>+~]\s*$|[>+~]\s*[>+~]/.test(selector)) return false;
    const atomPattern = /^(?:[A-Za-z][A-Za-z0-9_-]*|\*|#[A-Za-z_][A-Za-z0-9_-]*|\.[A-Za-z_][A-Za-z0-9_-]*|\[[A-Za-z_][A-Za-z0-9_-]*(?:=(?:[A-Za-z0-9_-]+|"[A-Za-z0-9 _-]*"))?\]|:(?:root|first-child|last-child|only-child|first-of-type|last-of-type|nth-child\([1-9][0-9]*\)|nth-of-type\([1-9][0-9]*\)))/;
    const compounds = [];
    let compound = '';
    let attributeDepth = 0;
    let quote = '';
    for (const character of selector) {
        if (quote) {
            compound += character;
            if (character === quote) quote = '';
            continue;
        }
        if (attributeDepth > 0 && (character === '"' || character === "'")) {
            quote = character;
            compound += character;
            continue;
        }
        if (character === '[') attributeDepth++;
        if (character === ']') attributeDepth--;
        if (attributeDepth === 0 && (/\s/.test(character) || character === '>' || character === '+' || character === '~')) {
            if (compound) compounds.push(compound);
            compound = '';
            continue;
        }
        compound += character;
    }
    if (compound) compounds.push(compound);
    if (compounds.length === 0) return false;
    return compounds.every(candidate => {
        let remainder = candidate;
        let atoms = 0;
        while (remainder.length > 0) {
            const match = atomPattern.exec(remainder);
            if (!match) return false;
            remainder = remainder.slice(match[0].length);
            atoms++;
        }
        return atoms > 0;
    });
}

/**
 * Validate a literal CSS color token without permitting resource or string functions.
 * @param {string} value Candidate color token.
 * @returns {boolean} True for safe named, hexadecimal, or numeric color syntax.
 */
function visualCssDraftColorIsValid(value) {
    return VISUAL_CSS_DRAFT_NAMED_COLORS.includes(value.toLowerCase())
        || /^#[A-Fa-f0-9]{3,4}$|^#[A-Fa-f0-9]{6}$|^#[A-Fa-f0-9]{8}$/.test(value)
        || /^(?:rgb|rgba|hsl|hsla)\([0-9.%+/,\s-]+\)$/i.test(value);
}

/**
 * Split CSS tokens only at delimiters outside a parenthesized literal function.
 * @param {string} value Candidate literal CSS value.
 * @param {string} delimiter One delimiter character to split on.
 * @returns {Array<string>} Trimmed non-empty top-level tokens.
 */
function splitVisualCssDraftTopLevel(value, delimiter) {
    const tokens = [];
    let depth = 0;
    let start = 0;
    for (let index = 0; index < value.length; index++) {
        const character = value[index];
        if (character === '(') depth++;
        else if (character === ')') depth--;
        else if (character === delimiter && depth === 0) {
            tokens.push(value.slice(start, index).trim());
            start = index + 1;
        }
    }
    tokens.push(value.slice(start).trim());
    return tokens;
}

/**
 * Validate one CSS length token and its sign policy.
 * @param {string} token Candidate numeric value and optional supported unit.
 * @param {boolean} allowNegative Whether the owning property permits negative lengths.
 * @returns {boolean} True for zero or a unit-bearing CSS length within the selected sign policy.
 */
function visualCssDraftLengthIsValid(token, allowNegative) {
    const match = token.match(/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))(?:px|rem|em|%|vh|vw|vmin|vmax|ch|ex|pt|pc|cm|mm|in|q)?$/i);
    if (!match) return false;
    const numeric = Number(match[1]);
    const hasUnit = /(?:px|rem|em|%|vh|vw|vmin|vmax|ch|ex|pt|pc|cm|mm|in|q)$/i.test(token);
    return Number.isFinite(numeric) && (allowNegative || numeric >= 0) && (numeric === 0 || hasUnit);
}

/**
 * Validate a space-separated bounded list of length values.
 * @param {string} value Candidate one-to-four or property-bounded length tokens.
 * @param {number} maximum Maximum number of values admitted for the property.
 * @param {boolean} allowNegative Whether negative values are permitted.
 * @param {boolean} allowAuto Whether the `auto` keyword is permitted.
 * @returns {boolean} True when every token belongs to the safe length grammar.
 */
function visualCssDraftLengthListIsValid(value, maximum, allowNegative, allowAuto) {
    const tokens = value.trim().split(/\s+/);
    return tokens.length > 0 && tokens.length <= maximum && tokens.every(token =>
        (allowAuto && token.toLowerCase() === 'auto') || visualCssDraftLengthIsValid(token, allowNegative));
}

/**
 * Validate one CSS property value from the visual editor's closed property set.
 * @param {string} property Canonical lowercase CSS property name.
 * @param {string} rawValue User-authored literal token, bounded shorthand, or exact Theme width template.
 * @returns {{valid:boolean,value:string,reason:string}} Trimmed value and bounded validation result.
 */
export function visualCssDraftPropertyValueIsValid(property, rawValue) {
    if (typeof property !== 'string' || !Object.prototype.hasOwnProperty.call(VISUAL_CSS_DRAFT_PROPERTY_GROUPS, property)) {
        return {valid: false, value: '', reason: 'property_unsupported'};
    }
    if (typeof rawValue !== 'string') return {valid: false, value: '', reason: 'value_shape'};
    const value = rawValue.trim();
    if (value.length === 0 || value.length > VISUAL_CSS_DRAFT_VALUE_MAX_LENGTH
        || /[\u0000-\u001f\u007f\\{};"'<>@]|\/\*/.test(value)
        || /(?:url|var|env|expression|image|image-set|cross-fade|paint|attr|element)\s*\(/i.test(value)
        || !/^[A-Za-z0-9#(),.%+\s/-]+$/.test(value)
        || !visualCssDraftParenthesesAreBalanced(value)) {
        return {valid: false, value, reason: 'value_unsafe'};
    }
    const group = VISUAL_CSS_DRAFT_PROPERTY_GROUPS[property];
    let valid = false;
    if (group === 'color') {
        valid = visualCssDraftColorIsValid(value);
    } else if (group === 'background-image') {
        valid = value.toLowerCase() === 'none';
    } else if (group === 'color-list') {
        const tokens = splitVisualCssDraftTopLevel(value, ' ');
        valid = tokens.length <= 4 && tokens.every(visualCssDraftColorIsValid);
    } else if (group === 'font-size') {
        valid = visualCssDraftLengthIsValid(value, false)
            || /^(?:xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger)$/i.test(value);
    } else if (group === 'font-weight') {
        valid = /^(?:normal|bold|bolder|lighter|[1-9]00)$/i.test(value);
    } else if (group === 'line-height') {
        valid = value.toLowerCase() === 'normal' || visualCssDraftLengthIsValid(value, false)
            || /^(?:\d+(?:\.\d*)?|\.\d+)$/.test(value);
    } else if (group === 'text-align') {
        valid = /^(?:left|right|center|justify|start|end|match-parent)$/i.test(value);
    } else if (group === 'text-decoration') {
        valid = /^(?:none|underline|overline|line-through)$/i.test(value);
        if (valid) return {valid: true, value: value.toLowerCase(), reason: 'ok'};
    } else if (group === 'backdrop-filter') {
        if (value.toLowerCase() === 'none') return {valid: true, value: 'none', reason: 'ok'};
        const filters = value.toLowerCase().split(/\s+/);
        let hasBlur = false;
        let hasSaturate = false;
        valid = filters.length > 0 && filters.length <= VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_FUNCTIONS && filters.every(filter => {
            const blur = filter.match(/^blur\((\d+(?:\.\d+)?)px\)$/);
            if (blur && !hasBlur && Number(blur[1]) <= VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_BLUR_PX) {
                hasBlur = true;
                return true;
            }
            const saturate = filter.match(/^saturate\((\d+(?:\.\d+)?)\)$/);
            if (saturate && !hasSaturate && Number(saturate[1]) <= VISUAL_CSS_DRAFT_BACKDROP_FILTER_MAX_SATURATION) {
                hasSaturate = true;
                return true;
            }
            return false;
        });
    } else if (group === 'length-list') {
        valid = visualCssDraftLengthListIsValid(value, 4, false, false);
    } else if (group === 'border-width') {
        valid = /^(?:thin|medium|thick)$/i.test(value) || visualCssDraftLengthListIsValid(value, 4, false, false);
    } else if (group === 'border-style') {
        valid = value.split(/\s+/).length <= 4 && value.split(/\s+/).every(token =>
            /^(?:none|hidden|dotted|dashed|solid|double|groove|ridge|inset|outset)$/i.test(token));
    } else if (group === 'shadow') {
        valid = value.toLowerCase() === 'none' || visualCssDraftShadowIsValid(value);
    } else if (group === 'gap') {
        valid = visualCssDraftLengthListIsValid(value, 2, false, false);
    } else if (group === 'size') {
        valid = visualCssDraftLengthIsValid(value, false)
            || /^(?:auto|min-content|max-content|fit-content)$/i.test(value)
            || property === 'width' && visualCssDraftThemeShellWidthValuePattern().test(value);
    } else if (group === 'opacity') {
        valid = /^(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/.test(value) && Number(value) >= 0 && Number(value) <= 1;
        if (valid) return {valid: true, value: String(Number(value)), reason: 'ok'};
    } else if (group === 'background-size') {
        valid = /^(?:cover|contain)$/i.test(value);
        if (valid) return {valid: true, value: value.toLowerCase(), reason: 'ok'};
    } else if (group === 'background-position') {
        const match = value.match(/^(\d{1,3})%\s+(\d{1,3})%$/);
        valid = match !== null && Number(match[1]) <= 100 && Number(match[2]) <= 100;
        if (valid && match) return {valid: true, value: `${Number(match[1])}% ${Number(match[2])}%`, reason: 'ok'};
    } else if (group === 'object-fit') {
        valid = /^(?:fill|contain|cover|none|scale-down)$/i.test(value);
    } else if (group === 'position') {
        valid = visualCssDraftPositionIsValid(value);
    } else if (group === 'margin-list') {
        valid = visualCssDraftLengthListIsValid(value, 4, true, true);
    }
    return {valid, value, reason: valid ? 'ok' : 'value_invalid_for_property'};
}

/**
 * Return the exact three public shell selectors that may use Theme page-width templates.
 * @param {string} selector Candidate public shell selector.
 * @returns {boolean} True only for the editor's three high-specificity shell targets.
 */
function visualCssDraftThemeShellSelectorIsValid(selector) {
    return ['body.public-page.public-page .site-header', 'body.public-page.public-page .site-main',
        'body.public-page.public-page .site-footer'].includes(selector);
}

/**
 * Return the narrow grammar for existing Theme page-width declarations.
 * @returns {RegExp} Pattern for the four existing public width templates and bounded custom width.
 */
function visualCssDraftThemeShellWidthValuePattern() {
    return /^(?:min\((?:1120|1440|1(?:02[4-9]|0[3-9][0-9]|[1-9][0-9]{2})|20(?:[0-3][0-9]|4[0-8]))px,calc\(100% - 2rem\)\)|calc\(100% - clamp\(1rem, 3vw, 3rem\)\))$/;
}

/**
 * Check Theme width templates against their permitted selector and site scope.
 * @param {string} selector Candidate public shell selector.
 * @param {string} scope Explicit visual-editor responsive scope.
 * @param {string} value Candidate existing Theme width expression.
 * @returns {boolean} True only for one of the exact safe templates on a public shell at site scope.
 */
function visualCssDraftThemeShellWidthValueIsValid(selector, scope, value) {
    return scope === 'site' && visualCssDraftThemeShellSelectorIsValid(selector)
        && typeof value === 'string' && visualCssDraftThemeShellWidthValuePattern().test(value.trim());
}

/**
 * Return whether all parenthesis pairs in a candidate value are balanced.
 * @param {string} value Candidate safe-character value.
 * @returns {boolean} True when parenthesis depth never becomes negative and ends at zero.
 */
function visualCssDraftParenthesesAreBalanced(value) {
    let depth = 0;
    for (const character of value) {
        if (character === '(') depth++;
        if (character === ')') depth--;
        if (depth < 0) return false;
    }
    return depth === 0;
}

/**
 * Validate one shadow list containing bounded lengths, optional literal colors, and optional inset.
 * @param {string} value Candidate box-shadow value.
 * @returns {boolean} True when each comma-separated shadow has two-to-four lengths and safe optional tokens.
 */
function visualCssDraftShadowIsValid(value) {
    const shadows = splitVisualCssDraftTopLevel(value, ',');
    if (shadows.length > 4) return false;
    return shadows.every(shadow => {
        const tokens = splitVisualCssDraftTopLevel(shadow, ' ');
        const lengths = tokens.filter(token => visualCssDraftLengthIsValid(token, true));
        const colors = tokens.filter(token => token.toLowerCase() !== 'inset' && visualCssDraftColorIsValid(token));
        const other = tokens.filter(token => token.toLowerCase() === 'inset');
        return lengths.length >= 2 && lengths.length <= 4 && colors.length <= 1 && other.length <= 1
            && lengths.length + colors.length + other.length === tokens.length;
    });
}

/**
 * Validate the one-to-four keyword or length values allowed for object positioning.
 * @param {string} value Candidate position shorthand.
 * @returns {boolean} True when each token is a safe axis keyword or dimension.
 */
function visualCssDraftPositionIsValid(value) {
    const tokens = value.split(/\s+/);
    return tokens.length <= 4 && tokens.every(token =>
        /^(?:left|right|top|bottom|center)$/i.test(token) || visualCssDraftLengthIsValid(token, false));
}

/**
 * Check selector, property, scope, and value together before a declaration enters model state, including Theme-only width and background-layer targets.
 * @param {VisualCssDraftRule} rule Candidate declaration supplied by the inspector or parser.
 * @returns {{valid:boolean,rule:VisualCssDraftRule|null,reason:string}} Normalized declaration or bounded validation failure.
 */
export function validateVisualCssDraftRule(rule) {
    if (!rule || typeof rule !== 'object') return {valid: false, rule: null, reason: 'rule_shape'};
    const scope = rule.scope;
    const selector = typeof rule.selector === 'string' ? rule.selector.trim() : '';
    const property = typeof rule.property === 'string' ? rule.property.toLowerCase() : '';
    let checkedValue = visualCssDraftPropertyValueIsValid(property, rule.value);
    if (!(scope === 'site' || scope === 'responsive:tablet' || scope === 'responsive:mobile')) {
        return {valid: false, rule: null, reason: 'scope_unsupported'};
    }
    if (!visualCssDraftSelectorIsValid(selector).valid) return {valid: false, rule: null, reason: 'selector_shape'};
    const themeWidthTemplate = property === 'width' && typeof rule.value === 'string'
        && visualCssDraftThemeShellWidthValuePattern().test(rule.value.trim());
    if (themeWidthTemplate) {
        if (!visualCssDraftThemeShellWidthValueIsValid(selector, scope, rule.value)) {
            return {valid: false, rule: null, reason: 'property_scope'};
        }
        checkedValue = {valid: true, value: rule.value.trim(), reason: 'ok'};
    } else if ((property === 'width' && visualCssDraftThemeShellSelectorIsValid(selector))
        || (['opacity', 'background-size', 'background-position'].includes(property)
            && (scope !== 'site' || selector !== 'body.public-page .theme-background-image'))) {
        return {valid: false, rule: null, reason: 'property_scope'};
    }
    if (!checkedValue.valid) return {valid: false, rule: null, reason: checkedValue.reason};
    const normalized = {scope, selector, property, value: checkedValue.value};
    if (Object.prototype.hasOwnProperty.call(rule, 'companionOf')) {
        const owner = rule.companionOf;
        const selectorMatch = selector.match(/^(.*)::(?:before|after)$/);
        if (!owner || typeof owner !== 'object' || Object.keys(owner).length !== 3
            || owner.scope !== scope || owner.property !== 'background-color'
            || typeof owner.selector !== 'string' || !selectorMatch || selectorMatch[1] !== owner.selector
            || property !== 'background-image' || checkedValue.value.toLowerCase() !== 'none') {
            return {valid: false, rule: null, reason: 'companion_provenance'};
        }
        normalized.value = 'none';
        normalized.companionOf = {scope, selector: owner.selector, property: 'background-color'};
    }
    return {valid: true, rule: normalized, reason: 'ok'};
}

/**
 * Validate the selector, property, and scope fields that identify a declaration.
 * @param {{scope:string,selector:string,property:string}} change Candidate property target.
 * @returns {{valid:boolean,scope:string,selector:string,property:string,reason:string}} Normalized identity or bounded failure.
 */
function validateVisualCssDraftIdentity(change) {
    if (!change || typeof change !== 'object') {
        return {valid: false, scope: '', selector: '', property: '', reason: 'rule_shape'};
    }
    const scope = change.scope;
    const selector = typeof change.selector === 'string' ? change.selector.trim() : '';
    const property = typeof change.property === 'string' ? change.property.toLowerCase() : '';
    if (!(scope === 'site' || scope === 'responsive:tablet' || scope === 'responsive:mobile')) {
        return {valid: false, scope, selector, property, reason: 'scope_unsupported'};
    }
    if (!visualCssDraftSelectorIsValid(selector).valid) {
        return {valid: false, scope, selector, property, reason: 'selector_shape'};
    }
    if (!Object.prototype.hasOwnProperty.call(VISUAL_CSS_DRAFT_PROPERTY_GROUPS, property)) {
        return {valid: false, scope, selector, property, reason: 'property_unsupported'};
    }
    return {valid: true, scope, selector, property, reason: 'ok'};
}

/**
 * Encode canonical declaration JSON for the comment-only managed-block integrity record.
 * @param {Array<VisualCssDraftRule>} rules Validated declarations in stable order.
 * @returns {string} Standard Base64 representation of ASCII-only canonical JSON.
 */
function encodeVisualCssDraftRules(rules) {
    return btoa(JSON.stringify(rules));
}

/**
 * Decode declaration JSON from one managed-block integrity record.
 * @param {string} encoded Standard Base64 bytes from the managed data comment.
 * @returns {Array<VisualCssDraftRule>|null} Parsed rule array, or null for invalid encoding or JSON.
 */
function decodeVisualCssDraftRules(encoded) {
    try {
        const parsed = JSON.parse(atob(encoded));
        return Array.isArray(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * Return a stable uniqueness key for one selector/property/scope target.
 * @param {VisualCssDraftRule} rule Validated declaration identity.
 * @returns {string} Collision-safe key for model-local declaration updates.
 */
function visualCssDraftRuleKey(rule) {
    return JSON.stringify([rule.scope, rule.selector, rule.property]);
}

/**
 * Validate a parsed rule list and reject duplicate selector/property/scope identities.
 * @param {Array<VisualCssDraftRule>} rules Parsed declaration candidates.
 * @returns {Array<VisualCssDraftRule>|null} Normalized unique declarations, or null when any record is malformed or duplicated.
 */
function normalizeVisualCssDraftRules(rules) {
    if (!Array.isArray(rules) || rules.length === 0 || rules.length > VISUAL_CSS_DRAFT_MAX_RULES) return null;
    const normalized = [];
    const seen = new Set();
    for (const candidate of rules) {
        const checked = validateVisualCssDraftRule(candidate);
        if (!checked.valid || !checked.rule) return null;
        const key = visualCssDraftRuleKey(checked.rule);
        if (seen.has(key)) return null;
        seen.add(key);
        normalized.push(checked.rule);
    }
    return visualCssDraftCompanionOwnershipIsConsistent(normalized) ? normalized : null;
}

/**
 * Verify that each tool-owned pseudo-element declaration has exactly one matching primary background rule.
 * @param {Array<VisualCssDraftRule>} rules Validated declaration set under parse or update review.
 * @returns {boolean} True when all companion provenance links resolve uniquely to a same-scope owner.
 */
function visualCssDraftCompanionOwnershipIsConsistent(rules) {
    const owned = rules.filter(rule => rule.companionOf);
    for (const companion of owned) {
        const matches = rules.filter(rule => rule.scope === companion.companionOf.scope
            && rule.selector === companion.companionOf.selector
            && rule.property === companion.companionOf.property);
        if (matches.length !== 1) return false;
        const siblings = owned.filter(candidate => candidate.companionOf.scope === companion.companionOf.scope
            && candidate.companionOf.selector === companion.companionOf.selector
            && candidate.companionOf.property === companion.companionOf.property);
        if (siblings.length !== 1) return false;
    }
    return true;
}

/**
 * Render declarations in one visual-editor scope without changing declaration order.
 * @param {Array<VisualCssDraftRule>} rules Validated rules for one scope.
 * @param {'\n'|'\r\n'} lineEnding Line ending selected from the original stylesheet.
 * @returns {string} CSS rule blocks for the requested scope.
 */
function renderVisualCssDraftScope(rules, lineEnding) {
    const grouped = new Map();
    for (const rule of rules) {
        if (!grouped.has(rule.selector)) grouped.set(rule.selector, []);
        grouped.get(rule.selector).push(rule);
    }
    return [...grouped.entries()].map(([selector, declarations]) =>
        selector + ' {' + lineEnding + declarations.map(rule => '  ' + rule.property + ': ' + rule.value + ';').join(lineEnding)
            + lineEnding + '}').join(lineEnding);
}

/**
 * Render all declarations into canonical site and explicitly selected responsive scopes.
 * @param {Array<VisualCssDraftRule>} rules Validated declaration records.
 * @param {'\n'|'\r\n'} lineEnding Line ending selected from the original stylesheet.
 * @returns {string} Managed CSS body with only media queries explicitly present in rule scopes.
 */
function renderVisualCssDraftBody(rules, lineEnding) {
    const blocks = [];
    const siteRules = rules.filter(rule => rule.scope === 'site');
    if (siteRules.length > 0) blocks.push(renderVisualCssDraftScope(siteRules, lineEnding));
    for (const breakpoint of ['tablet', 'mobile']) {
        const scopedRules = rules.filter(rule => rule.scope === 'responsive:' + breakpoint);
        if (scopedRules.length > 0) {
            blocks.push('@media (max-width: ' + VISUAL_CSS_DRAFT_BREAKPOINTS[breakpoint] + 'px) {' + lineEnding
                + renderVisualCssDraftScope(scopedRules, lineEnding).split(lineEnding).map(line => '  ' + line).join(lineEnding)
                + lineEnding + '}');
        }
    }
    return blocks.join(lineEnding);
}

/**
 * Render one canonical owned block that can be revalidated byte-for-byte on a later session.
 * @param {Array<VisualCssDraftRule>} rules Validated unique declaration records.
 * @param {VisualCssDraftBlock} block Exact source boundary and line policy.
 * @returns {string} Complete managed comment/data/CSS block without modifying surrounding bytes.
 */
function renderVisualCssDraftBlock(rules, block) {
    const separator = block.separator;
    const lineEndingName = block.lineEnding === '\r\n' ? 'crlf' : 'lf';
    const begin = '/* PHP Gallery managed visual CSS BEGIN v1 separator=' + separator + ' line-ending=' + lineEndingName + ' */';
    const data = '/* PHP Gallery managed visual CSS DATA ' + encodeVisualCssDraftRules(rules) + ' */';
    const body = renderVisualCssDraftBody(rules, block.lineEnding);
    return begin + block.lineEnding + data + block.lineEnding + body + block.lineEnding + '/* PHP Gallery managed visual CSS END v1 */';
}

/**
 * Count marker-kind occurrences, including malformed versions, to fail closed on user conflicts.
 * @param {string} sourceCss Exact input stylesheet bytes.
 * @param {'BEGIN'|'DATA'|'END'} kind Marker kind to count.
 * @returns {number} Number of marker-prefix occurrences in the source.
 */
function countVisualCssDraftMarkers(sourceCss, kind) {
    const prefix = kind === 'BEGIN' ? VISUAL_CSS_DRAFT_BEGIN_PREFIX : kind === 'DATA' ? VISUAL_CSS_DRAFT_DATA_PREFIX : VISUAL_CSS_DRAFT_END_PREFIX;
    return sourceCss.split(prefix).length - 1;
}

/**
 * Build a fail-closed model that retains the exact original CSS after a marker or parse conflict.
 * @param {string} sourceCss Exact source bytes that must remain untouched.
 * @param {string} warning Bounded explanation that prevents unsafe managed editing.
 * @returns {VisualCssDraftModel} Invalid model with original CSS and no admitted declarations.
 */
function invalidVisualCssDraft(sourceCss, warning) {
    const history = [[]];
    return createVisualCssDraftModel(sourceCss, sourceCss, [], warning, false, history, 0, sourceCss, null, false);
}

/**
 * Parse one managed CSS block while preserving every byte outside its markers.
 * @param {string} sourceCss Exact authored stylesheet bytes loaded by the editor.
 * @returns {VisualCssDraftModel} Immutable model; malformed or duplicate markers retain the original CSS and expose a warning.
 */
export function parseVisualCssDraft(sourceCss) {
    if (typeof sourceCss !== 'string') return invalidVisualCssDraft('', 'The CSS draft is not text.');
    const markerCount = ['BEGIN', 'DATA', 'END'].reduce((total, kind) => total + countVisualCssDraftMarkers(sourceCss, kind), 0);
    if (markerCount === 0) {
        return createVisualCssDraftModel(sourceCss, sourceCss, [], null, true, [[]], 0, sourceCss, null, false);
    }
    const begins = [...sourceCss.matchAll(VISUAL_CSS_DRAFT_BEGIN_LINE)];
    const dataLines = [...sourceCss.matchAll(VISUAL_CSS_DRAFT_DATA_LINE)];
    const ends = [...sourceCss.matchAll(VISUAL_CSS_DRAFT_END_LINE)];
    if (markerCount !== 3 || begins.length !== 1 || dataLines.length !== 1 || ends.length !== 1) {
        return invalidVisualCssDraft(sourceCss, 'The visual CSS managed-block markers conflict; the draft was left unchanged.');
    }
    const beginMatch = begins[0];
    const dataMatch = dataLines[0];
    const endMatch = ends[0];
    const beginEnd = beginMatch.index + beginMatch[0].length;
    const beginLineEnding = sourceCss.charAt(beginEnd) === '\n' ? '\n' : '';
    const dataEnd = dataMatch.index + dataMatch[0].length;
    const dataLineEnding = sourceCss.charAt(dataEnd) === '\n' ? '\n' : '';
    const lineEnding = beginMatch[2] === 'crlf' ? '\r\n' : '\n';
    const dataStart = dataMatch[1];
    const separator = beginMatch[1];
    const markerStart = beginMatch.index;
    // A CRLF end-marker match includes the carriage return, but the serialized block does not.
    // Keep both CR and LF in the preserved suffix so a reopened block stays byte-identical.
    const markerEnd = endMatch.index + endMatch[0].length - (endMatch[0].endsWith('\r') ? 1 : 0);
    if (beginLineEnding !== '\n' || dataLineEnding !== '\n' || beginMatch.index >= dataMatch.index
        || dataMatch.index >= endMatch.index || beginLineEnding !== (lineEnding === '\r\n' ? '\n' : '\n')
        || dataLineEnding !== '\n') {
        return invalidVisualCssDraft(sourceCss, 'The visual CSS managed block has an invalid line structure; the draft was left unchanged.');
    }
    const rules = decodeVisualCssDraftRules(dataStart);
    const normalizedRules = normalizeVisualCssDraftRules(rules || []);
    if (!normalizedRules) return invalidVisualCssDraft(sourceCss, 'The visual CSS managed block contains invalid or duplicate declarations; the draft was left unchanged.');
    const prefix = sourceCss.slice(0, markerStart);
    const suffix = sourceCss.slice(markerEnd);
    const block = {prefix, suffix, separator, lineEnding};
    const actualBlock = sourceCss.slice(markerStart, markerEnd);
    if (actualBlock !== renderVisualCssDraftBlock(normalizedRules, block)) {
        return invalidVisualCssDraft(sourceCss, 'The visual CSS managed block was edited outside the visual editor; the draft was left unchanged.');
    }
    const separatorBytes = separator === 'crlf' ? '\r\n' : separator === 'lf' ? '\n' : '';
    if (separatorBytes !== '' && !prefix.endsWith(separatorBytes)) {
        return invalidVisualCssDraft(sourceCss, 'The visual CSS managed block separator is invalid; the draft was left unchanged.');
    }
    const basePrefix = separatorBytes === '' ? prefix : prefix.slice(0, -separatorBytes.length);
    const baseCss = basePrefix + suffix;
    return createVisualCssDraftModel(sourceCss, sourceCss, normalizedRules, null, true, [normalizedRules], 0, baseCss, block, true);
}

/**
 * Create the source boundary to use when a stylesheet without a managed block first receives a rule.
 * @param {string} sourceCss Exact stylesheet bytes from the previous state.
 * @returns {VisualCssDraftBlock} Prefix, suffix, separator and preferred line ending for serialization.
 */
function createVisualCssDraftBlockContext(sourceCss) {
    const lineEnding = sourceCss.includes('\r\n') ? '\r\n' : '\n';
    const suffixMatch = sourceCss.match(/(?:\r?\n[ \t]*\/\*(?:(?!\*\/)[\s\S])*\*\/[ \t]*)+$/);
    const suffix = suffixMatch ? suffixMatch[0] : '';
    const base = suffixMatch ? sourceCss.slice(0, -suffix.length) : sourceCss;
    const separator = base.endsWith('\n') ? 'none' : lineEnding === '\r\n' ? 'crlf' : 'lf';
    const separatorBytes = separator === 'crlf' ? '\r\n' : separator === 'lf' ? '\n' : '';
    return {prefix: base + separatorBytes, suffix, separator, lineEnding};
}

/**
 * Serialize a model using its preserved byte boundaries and current declaration snapshot.
 * @param {VisualCssDraftModel} draft Immutable parsed or edited CSS model.
 * @param {Array<VisualCssDraftRule>} rules Explicit declarations to serialize.
 * @param {VisualCssDraftBlock|null} block Current managed block boundaries, if one has been created.
 * @param {boolean} hasManagedBlock Whether the selected rules need a serialized block.
 * @returns {{css:string,block:VisualCssDraftBlock|null,hasManagedBlock:boolean}} CSS output and its continued source boundary.
 */
function serializeVisualCssDraftState(draft, rules, block, hasManagedBlock) {
    if (rules.length === 0) {
        if (!block || !hasManagedBlock) return {css: draft.baseCss, block, hasManagedBlock: false};
        const separatorBytes = block.separator === 'crlf' ? '\r\n' : block.separator === 'lf' ? '\n' : '';
        const prefix = separatorBytes && block.prefix.endsWith(separatorBytes)
            ? block.prefix.slice(0, -separatorBytes.length)
            : block.prefix;
        return {css: prefix + block.suffix, block, hasManagedBlock: false};
    }
    const nextBlock = block || createVisualCssDraftBlockContext(draft.baseCss);
    return {
        css: nextBlock.prefix + renderVisualCssDraftBlock(rules, nextBlock) + nextBlock.suffix,
        block: nextBlock,
        hasManagedBlock: true,
    };
}

/**
 * Apply one complete immutable declaration snapshot and update model history only when bytes change.
 * @param {VisualCssDraftModel} draft Valid CSS model and current history cursor.
 * @param {Array<VisualCssDraftRule>} rules New declaration snapshot.
 * @returns {VisualCssDraftModel} Updated immutable model with one new undo step, or the same model for a no-op.
 */
function commitVisualCssDraftSnapshot(draft, rules) {
    if (JSON.stringify(draft.rules) === JSON.stringify(rules)) return draft;
    const nextHistory = draft.history.slice(0, draft.historyIndex + 1).map(snapshot => snapshot.map(rule => ({...rule})));
    nextHistory.push(rules.map(rule => ({...rule})));
    const historyIndex = nextHistory.length - 1;
    const serialized = serializeVisualCssDraftState(draft, rules, draft.block, draft.hasManagedBlock);
    return createVisualCssDraftModel(serialized.css, draft.sourceCss, rules, null, true,
        nextHistory, historyIndex, draft.baseCss, serialized.block, serialized.hasManagedBlock);
}

/**
 * Apply several explicitly authored property updates as one undoable user action.
 * @param {VisualCssDraftModel} draft Current immutable CSS draft.
 * @param {Array<{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:string,value:string|null,companionOf?:{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:'background-color'}}>} changes Ordered property updates; null values remove one property, and companionOf links an editor-owned background-image:none declaration to its primary background-color declaration.
 * @returns {VisualCssDraftModel} New draft with one grouped history snapshot, or unchanged CSS and warning on invalid input.
 */
export function applyVisualCssDraftChanges(draft, changes) {
    if (!draft || !draft.valid || !Array.isArray(changes)) {
        return draft && typeof draft.css === 'string'
            ? Object.freeze({...draft, warning: 'The visual CSS draft cannot accept this change.'})
            : invalidVisualCssDraft('', 'The visual CSS draft cannot accept this change.');
    }
    let nextRules = draft.rules.map(rule => ({...rule}));
    for (const change of changes) {
        if (!change || typeof change !== 'object') {
            return Object.freeze({...draft, warning: 'The visual CSS change is malformed; existing CSS was kept.'});
        }
        const identity = validateVisualCssDraftIdentity(change);
        if (!identity.valid) {
            return Object.freeze({...draft, warning: 'The visual CSS change is unsupported or unsafe; existing CSS was kept.'});
        }
        const key = JSON.stringify([identity.scope, identity.selector, identity.property]);
        const existingIndex = nextRules.findIndex(rule => visualCssDraftRuleKey(rule) === key);
        if (change.value === null) {
            if (existingIndex >= 0) nextRules.splice(existingIndex, 1);
            continue;
        }
        const existing = existingIndex >= 0 ? nextRules[existingIndex] : null;
        const candidate = {...identity, value: change.value};
        if (Object.prototype.hasOwnProperty.call(change, 'companionOf')) candidate.companionOf = change.companionOf;
        else if (existing?.companionOf) candidate.companionOf = existing.companionOf;
        const validated = validateVisualCssDraftRule(candidate);
        if (!validated.valid || !validated.rule) {
            return Object.freeze({...draft, warning: 'The visual CSS change is unsupported or unsafe; existing CSS was kept.'});
        }
        const updated = {...validated.rule};
        if (existingIndex >= 0) nextRules[existingIndex] = updated;
        else nextRules.push(updated);
    }
    if (nextRules.length > VISUAL_CSS_DRAFT_MAX_RULES) {
        return Object.freeze({...draft, warning: 'The visual CSS draft reached its supported declaration limit; existing CSS was kept.'});
    }
    if (!visualCssDraftCompanionOwnershipIsConsistent(nextRules)) {
        return Object.freeze({...draft, warning: 'The visual CSS overlay link is incomplete or ambiguous; existing CSS was kept.'});
    }
    return commitVisualCssDraftSnapshot(draft, nextRules);
}

/**
 * Update one selected selector/property declaration with a validated authored CSS token.
 * @param {VisualCssDraftModel} draft Current immutable CSS draft.
 * @param {{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:string,value:string}} change One explicit declaration update.
 * @returns {VisualCssDraftModel} Updated immutable draft or unchanged CSS with a warning.
 */
export function updateVisualCssDraftProperty(draft, change) {
    return applyVisualCssDraftChanges(draft, [change]);
}

/**
 * Remove exactly one selected selector/property declaration.
 * @param {VisualCssDraftModel} draft Current immutable CSS draft.
 * @param {{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:string}} target Declaration identity to remove.
 * @returns {VisualCssDraftModel} Updated immutable draft or unchanged CSS when the declaration was absent.
 */
export function removeVisualCssDraftProperty(draft, target) {
    return applyVisualCssDraftChanges(draft, [{...target, value: null}]);
}

/**
 * Restore the preceding complete declaration snapshot without mutating prior model values.
 * @param {VisualCssDraftModel} draft Current immutable CSS draft.
 * @returns {VisualCssDraftModel} Previous snapshot, or the same model when no undo step exists.
 */
export function undoVisualCssDraft(draft) {
    if (!draft || !draft.valid || draft.historyIndex <= 0) return draft;
    const historyIndex = draft.historyIndex - 1;
    const rules = draft.history[historyIndex];
    const serialized = serializeVisualCssDraftState(draft, rules, draft.block, draft.hasManagedBlock);
    return createVisualCssDraftModel(serialized.css, draft.sourceCss, rules, null, true,
        draft.history, historyIndex, draft.baseCss, serialized.block, serialized.hasManagedBlock);
}

/**
 * Reapply the next complete declaration snapshot without mutating prior model values.
 * @param {VisualCssDraftModel} draft Current immutable CSS draft.
 * @returns {VisualCssDraftModel} Next snapshot, or the same model when no redo step exists.
 */
export function redoVisualCssDraft(draft) {
    if (!draft || !draft.valid || draft.historyIndex >= draft.history.length - 1) return draft;
    const historyIndex = draft.historyIndex + 1;
    const rules = draft.history[historyIndex];
    const serialized = serializeVisualCssDraftState(draft, rules, draft.block, draft.hasManagedBlock);
    return createVisualCssDraftModel(serialized.css, draft.sourceCss, rules, null, true,
        draft.history, historyIndex, draft.baseCss, serialized.block, serialized.hasManagedBlock);
}

/**
 * Return the exact stylesheet bytes currently represented by a visual CSS draft.
 * @param {VisualCssDraftModel} draft Parsed or edited CSS model.
 * @returns {string} Exact original text on a no-op or validation failure, otherwise canonical CSS around the owned block.
 */
export function serializeVisualCssDraft(draft) {
    return draft && typeof draft.css === 'string' ? draft.css : '';
}
