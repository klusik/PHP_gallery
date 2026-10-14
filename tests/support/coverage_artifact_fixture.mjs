/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/coverage_artifact_fixture.mjs
 * Module Type: Coverage Artifact Fixture
 * Purpose: Build inert ZIP bytes for authenticated hosted-evidence regression scenarios.
 * Responsibilities:
 *   - Share real ZIP headers, CRC values and optional compression across coverage consumers
 *   - Keep synthetic archives in memory without network or repository writes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {deflateRawSync} from 'node:zlib';

/** Calculate the unsigned ZIP CRC-32 for one fixture entry.
 * @param {Buffer} bytes Complete uncompressed entry bytes.
 * @returns {number} Unsigned 32-bit CRC stored in the ZIP headers.
 */
function coverageFixtureCrc32(bytes) {
    let crc=0xffffffff;
    for (const byte of bytes) {
        crc^=byte;
        for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);
    }
    return (crc^0xffffffff)>>>0;
}

/** Encode ordered fixture entries into a complete ZIP with real headers and checksums.
 * @param {Array<[string,Buffer]>} files Entry names and complete uncompressed bytes; malformed names remain available for negative scenarios.
 * @param {number} method ZIP compression method, defaulting to stored bytes; method 8 uses raw DEFLATE.
 * @param {boolean} descriptor Whether to put sizes and CRC in trailing data descriptors instead of local headers, defaulting to false.
 * @returns {Buffer} Complete in-memory ZIP bytes including the central directory and end record.
 */
export function coverageFixtureZip(files,method=0,descriptor=false) {
    const locals=[],central=[];
    let offset=0;
    for (const [name,bytes] of files) {
        const label=Buffer.from(name),encoded=method===8?deflateRawSync(bytes):bytes;
        const crc=coverageFixtureCrc32(bytes),flags=descriptor?8:0;
        const local=Buffer.alloc(30),entry=Buffer.alloc(46);
        local.writeUInt32LE(0x04034b50);local.writeUInt16LE(20,4);local.writeUInt16LE(flags,6);
        local.writeUInt16LE(method,8);local.writeUInt16LE(label.length,26);
        if (!descriptor) {local.writeUInt32LE(crc,14);local.writeUInt32LE(encoded.length,18);local.writeUInt32LE(bytes.length,22);}
        const tail=descriptor?Buffer.alloc(16):Buffer.alloc(0);
        if (descriptor) {tail.writeUInt32LE(0x08074b50);tail.writeUInt32LE(crc,4);tail.writeUInt32LE(encoded.length,8);tail.writeUInt32LE(bytes.length,12);}
        entry.writeUInt32LE(0x02014b50);entry.writeUInt16LE(20,6);entry.writeUInt16LE(flags,8);
        entry.writeUInt16LE(method,10);entry.writeUInt32LE(crc,16);entry.writeUInt32LE(encoded.length,20);
        entry.writeUInt32LE(bytes.length,24);entry.writeUInt16LE(label.length,28);entry.writeUInt32LE(offset,42);
        const block=Buffer.concat([local,label,encoded,tail]);
        locals.push(block);central.push(Buffer.concat([entry,label]));offset+=block.length;
    }
    const directory=Buffer.concat(central),end=Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50);end.writeUInt16LE(files.length,8);end.writeUInt16LE(files.length,10);
    end.writeUInt32LE(directory.length,12);end.writeUInt32LE(offset,16);
    return Buffer.concat([...locals,directory,end]);
}
