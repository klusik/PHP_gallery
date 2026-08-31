/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/zip-stream-writer.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Writes standards-compliant stored ZIP archives incrementally without retaining file payloads.
 *
 * Responsibilities:
 *   - Emit local headers, data descriptors, central directory records, and ZIP64 metadata
 *   - Calculate CRC-32 incrementally while source bytes are streamed
 *   - Keep archive offsets as BigInt values so files above classic ZIP limits remain valid
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

const ZIP32_MAX = 0xffffffffn;
const ZIP16_MAX = 0xffff;
const UTF8_DATA_DESCRIPTOR_FLAGS = 0x0808;
const STORED_METHOD = 0;
const VERSION_CLASSIC = 20;
const VERSION_ZIP64 = 45;

const CRC32_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let i = 0; i < 256; i += 1) {
        let value = i;
        for (let bit = 0; bit < 8; bit += 1) {
            value = (value & 1) !== 0 ? (0xedb88320 ^ (value >>> 1)) : (value >>> 1);
        }
        table[i] = value >>> 0;
    }
    return table;
})();

function crc32Update(crc, bytes) {
    let value = crc >>> 0;
    for (let i = 0; i < bytes.length; i += 1) {
        value = CRC32_TABLE[(value ^ bytes[i]) & 0xff] ^ (value >>> 8);
    }
    return value >>> 0;
}

function writeUint16(view, offset, value) {
    view.setUint16(offset, Number(value), true);
}

function writeUint32(view, offset, value) {
    view.setUint32(offset, Number(BigInt(value) & ZIP32_MAX), true);
}

function writeUint64(view, offset, value) {
    let remaining = BigInt(value);
    view.setUint32(offset, Number(remaining & ZIP32_MAX), true);
    remaining >>= 32n;
    view.setUint32(offset + 4, Number(remaining & ZIP32_MAX), true);
}

function concatBytes(...parts) {
    const total = parts.reduce((sum, part) => sum + part.length, 0);
    const result = new Uint8Array(total);
    let offset = 0;
    for (const part of parts) {
        result.set(part, offset);
        offset += part.length;
    }
    return result;
}

function dosDateTime(date = new Date()) {
    const year = Math.min(2107, Math.max(1980, date.getFullYear()));
    const month = date.getMonth() + 1;
    const day = date.getDate();
    const hours = date.getHours();
    const minutes = date.getMinutes();
    const seconds = Math.floor(date.getSeconds() / 2);
    return {
        time: (hours << 11) | (minutes << 5) | seconds,
        date: ((year - 1980) << 9) | (month << 5) | day,
    };
}

function zip64Extra(values) {
    const payloadLength = values.length * 8;
    const bytes = new Uint8Array(4 + payloadLength);
    const view = new DataView(bytes.buffer);
    writeUint16(view, 0, 0x0001);
    writeUint16(view, 2, payloadLength);
    values.forEach((value, index) => writeUint64(view, 4 + index * 8, value));
    return bytes;
}

function localHeader(nameBytes, expectedSize, dateParts) {
    const zip64 = expectedSize >= ZIP32_MAX;
    const extra = zip64 ? zip64Extra([expectedSize, expectedSize]) : new Uint8Array(0);
    const bytes = new Uint8Array(30);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x04034b50);
    writeUint16(view, 4, zip64 ? VERSION_ZIP64 : VERSION_CLASSIC);
    writeUint16(view, 6, UTF8_DATA_DESCRIPTOR_FLAGS);
    writeUint16(view, 8, STORED_METHOD);
    writeUint16(view, 10, dateParts.time);
    writeUint16(view, 12, dateParts.date);
    writeUint32(view, 14, 0);
    writeUint32(view, 18, zip64 ? ZIP32_MAX : 0);
    writeUint32(view, 22, zip64 ? ZIP32_MAX : 0);
    writeUint16(view, 26, nameBytes.length);
    writeUint16(view, 28, extra.length);
    return {bytes: concatBytes(bytes, nameBytes, extra), zip64};
}

function dataDescriptor(crc, size, zip64) {
    const bytes = new Uint8Array(zip64 ? 24 : 16);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x08074b50);
    writeUint32(view, 4, crc >>> 0);
    if (zip64) {
        writeUint64(view, 8, size);
        writeUint64(view, 16, size);
    } else {
        writeUint32(view, 8, size);
        writeUint32(view, 12, size);
    }
    return bytes;
}

function centralDirectoryHeader(entry) {
    const needsSize64 = entry.size >= ZIP32_MAX;
    const needsOffset64 = entry.offset >= ZIP32_MAX;
    const extraValues = [];
    if (needsSize64) {
        extraValues.push(entry.size, entry.size);
    }
    if (needsOffset64) {
        extraValues.push(entry.offset);
    }
    const extra = extraValues.length > 0 ? zip64Extra(extraValues) : new Uint8Array(0);
    const bytes = new Uint8Array(46);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x02014b50);
    writeUint16(view, 4, VERSION_ZIP64);
    writeUint16(view, 6, (needsSize64 || needsOffset64) ? VERSION_ZIP64 : VERSION_CLASSIC);
    writeUint16(view, 8, UTF8_DATA_DESCRIPTOR_FLAGS);
    writeUint16(view, 10, STORED_METHOD);
    writeUint16(view, 12, entry.time);
    writeUint16(view, 14, entry.date);
    writeUint32(view, 16, entry.crc);
    writeUint32(view, 20, needsSize64 ? ZIP32_MAX : entry.size);
    writeUint32(view, 24, needsSize64 ? ZIP32_MAX : entry.size);
    writeUint16(view, 28, entry.nameBytes.length);
    writeUint16(view, 30, extra.length);
    writeUint16(view, 32, 0);
    writeUint16(view, 34, 0);
    writeUint16(view, 36, 0);
    writeUint32(view, 38, 0);
    writeUint32(view, 42, needsOffset64 ? ZIP32_MAX : entry.offset);
    return concatBytes(bytes, entry.nameBytes, extra);
}

function zip64EndOfCentralDirectory(entryCount, centralSize, centralOffset) {
    const bytes = new Uint8Array(56);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x06064b50);
    writeUint64(view, 4, 44n);
    writeUint16(view, 12, VERSION_ZIP64);
    writeUint16(view, 14, VERSION_ZIP64);
    writeUint32(view, 16, 0);
    writeUint32(view, 20, 0);
    writeUint64(view, 24, entryCount);
    writeUint64(view, 32, entryCount);
    writeUint64(view, 40, centralSize);
    writeUint64(view, 48, centralOffset);
    return bytes;
}

function zip64Locator(zip64EocdOffset) {
    const bytes = new Uint8Array(20);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x07064b50);
    writeUint32(view, 4, 0);
    writeUint64(view, 8, zip64EocdOffset);
    writeUint32(view, 16, 1);
    return bytes;
}

function endOfCentralDirectory(entryCount, centralSize, centralOffset, useZip64) {
    const bytes = new Uint8Array(22);
    const view = new DataView(bytes.buffer);
    writeUint32(view, 0, 0x06054b50);
    writeUint16(view, 4, 0);
    writeUint16(view, 6, 0);
    writeUint16(view, 8, useZip64 ? ZIP16_MAX : entryCount);
    writeUint16(view, 10, useZip64 ? ZIP16_MAX : entryCount);
    writeUint32(view, 12, useZip64 ? ZIP32_MAX : centralSize);
    writeUint32(view, 16, useZip64 ? ZIP32_MAX : centralOffset);
    writeUint16(view, 20, 0);
    return bytes;
}

/**
 * Incremental stored-method ZIP writer.
 */
export function zip64RequiredForArchive(entryCount, centralSize, centralOffset, entries = []) {
    return BigInt(entryCount) >= BigInt(ZIP16_MAX)
        || BigInt(centralSize) >= ZIP32_MAX
        || BigInt(centralOffset) >= ZIP32_MAX
        || entries.some((entry) => BigInt(entry.size) >= ZIP32_MAX || BigInt(entry.offset) >= ZIP32_MAX);
}

export class ZipStreamWriter {
    constructor(sink) {
        if (!sink || typeof sink.write !== 'function') {
            throw new TypeError('ZIP sink must provide write(Uint8Array).');
        }
        this.sink = sink;
        this.offset = 0n;
        this.entries = [];
        this.closed = false;
        this.encoder = new TextEncoder();
    }

    async write(bytes) {
        if (!(bytes instanceof Uint8Array)) {
            bytes = new Uint8Array(bytes);
        }
        await this.sink.write(bytes);
        this.offset += BigInt(bytes.byteLength);
    }

    /**
     * Add one file from a ReadableStream while calculating CRC and byte count.
     */
    async addReadable(name, expectedSize, readable, onChunk = null) {
        if (this.closed) {
            throw new Error('ZIP writer is already finalized.');
        }
        if (!readable || typeof readable.getReader !== 'function') {
            throw new Error('Response body streaming is not available.');
        }
        const size = BigInt(expectedSize);
        if (size < 0n) {
            throw new Error('Invalid expected file size.');
        }
        const parts = String(name).split('/');
        if (String(name).startsWith('/')
            || String(name).includes('\\')
            || String(name).includes(':')
            || /[\u0000-\u001f\u007f]/u.test(String(name))
            || parts.some((part) => part === '' || part === '.' || part === '..')) {
            throw new Error('ZIP entry name is unsafe.');
        }
        const nameBytes = this.encoder.encode(String(name));
        if (nameBytes.length === 0 || nameBytes.length > 0xffff) {
            throw new Error('ZIP entry name is invalid or too long.');
        }
        const startedAt = this.offset;
        const dateParts = dosDateTime();
        const header = localHeader(nameBytes, size, dateParts);
        await this.write(header.bytes);

        let actualSize = 0n;
        let crc = 0xffffffff;
        const reader = readable.getReader();
        try {
            while (true) {
                const {done, value} = await reader.read();
                if (done) {
                    break;
                }
                const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
                actualSize += BigInt(bytes.byteLength);
                if (actualSize > size) {
                    throw new Error('Downloaded file exceeded the declared size.');
                }
                crc = crc32Update(crc, bytes);
                await this.write(bytes);
                if (typeof onChunk === 'function') {
                    onChunk(bytes.byteLength);
                }
            }
        } finally {
            reader.releaseLock();
        }
        if (actualSize !== size) {
            throw new Error('Downloaded file length did not match the manifest.');
        }
        crc = (crc ^ 0xffffffff) >>> 0;
        await this.write(dataDescriptor(crc, actualSize, header.zip64));
        this.entries.push({
            nameBytes,
            size: actualSize,
            crc,
            offset: startedAt,
            time: dateParts.time,
            date: dateParts.date,
        });
    }

    /**
     * Write central directory records and close the logical ZIP stream.
     */
    async finalize() {
        if (this.closed) {
            return;
        }
        const centralOffset = this.offset;
        for (const entry of this.entries) {
            await this.write(centralDirectoryHeader(entry));
        }
        const centralSize = this.offset - centralOffset;
        const entryCount = BigInt(this.entries.length);
        const useZip64 = zip64RequiredForArchive(entryCount, centralSize, centralOffset, this.entries);
        if (useZip64) {
            const zip64EocdOffset = this.offset;
            await this.write(zip64EndOfCentralDirectory(entryCount, centralSize, centralOffset));
            await this.write(zip64Locator(zip64EocdOffset));
        }
        await this.write(endOfCentralDirectory(entryCount, centralSize, centralOffset, useZip64));
        this.closed = true;
    }
}

export function crc32ForBytes(bytes) {
    return (crc32Update(0xffffffff, bytes) ^ 0xffffffff) >>> 0;
}
