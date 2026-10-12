/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/artifact-data.mjs
 * Module Type: Hosted Artifact Data Reader
 * Purpose: Authenticate bounded ZIP data without extracting or executing its contents.
 * Responsibilities:
 *   - Match provider SHA-256 before parsing and validate complete ZIP entry bytes
 *   - Reject unexpected names, duplicates, encryption, ZIP64, corruption and oversized data
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {createHash} from 'node:crypto';
import {inflateRawSync} from 'node:zlib';

/** Calculate the ZIP CRC-32 of complete uncompressed entry bytes.
 * @param {Buffer} bytes Exact entry content.
 * @returns {number} Unsigned ZIP CRC-32 checksum.
 */
function zipCrc(bytes) {
    let crc=0xffffffff;
    for (const byte of bytes) {
        crc^=byte;
        for (let bit=0;bit<8;bit++) crc=(crc>>>1)^((crc&1)?0xedb88320:0);
    }
    return (crc^0xffffffff)>>>0;
}

/** Authenticate and parse a complete small provider ZIP as in-memory data only.
 * @param {Buffer} archiveBytes Complete downloaded ZIP bytes.
 * @param {string} expectedDigest Provider digest in sha256:hex form.
 * @param {{allowedNames:string[],maxBytes:number}} options Exact permitted file names and combined archive/uncompressed byte ceiling.
 * @returns {Map<string,Buffer>} Complete unique permitted entries; omitted, unexpected or corrupt entries throw.
 */
export function parseVerifiedZip(archiveBytes,expectedDigest,options) {
    const names=options?.allowedNames;
    const limit=options?.maxBytes;
    if (!Buffer.isBuffer(archiveBytes) || !Number.isSafeInteger(limit) || limit<22
        || archiveBytes.length>limit || archiveBytes.length<22 || !Array.isArray(names) || names.length<1
        || new Set(names).size!==names.length || names.some(name=>typeof name!=='string'
            || name.startsWith('/') || name.includes('\\') || name.split('/').some(part=>!part || part==='.' || part==='..'))
        || !/^sha256:[a-f0-9]{64}$/.test(expectedDigest ?? '')
        || 'sha256:'+createHash('sha256').update(archiveBytes).digest('hex')!==expectedDigest) {
        throw new Error('BLOCKED artifact ZIP digest, size or allowed-name contract differs.');
    }
    let end=-1;
    for (let at=archiveBytes.length-22;at>=Math.max(0,archiveBytes.length-65557);at--) {
        if (archiveBytes.readUInt32LE(at)===0x06054b50 && at+22+archiveBytes.readUInt16LE(at+20)===archiveBytes.length) {
            end=at;break;
        }
    }
    if (end<0) throw new Error('BLOCKED missing complete ZIP end record.');
    const count=archiveBytes.readUInt16LE(end+10),directorySize=archiveBytes.readUInt32LE(end+12);
    const directoryOffset=archiveBytes.readUInt32LE(end+16);
    if (archiveBytes.readUInt16LE(end+4)!==0 || archiveBytes.readUInt16LE(end+6)!==0
        || archiveBytes.readUInt16LE(end+8)!==count || count!==names.length || count===65535
        || directoryOffset+directorySize!==end) throw new Error('BLOCKED incomplete or unsupported ZIP directory.');
    let position=directoryOffset,total=0;
    const result=new Map(),ranges=[];
    for (let index=0;index<count;index++) {
        if (position+46>end || archiveBytes.readUInt32LE(position)!==0x02014b50) {
            throw new Error('BLOCKED malformed ZIP central entry.');
        }
        const flags=archiveBytes.readUInt16LE(position+8),method=archiveBytes.readUInt16LE(position+10);
        const crc=archiveBytes.readUInt32LE(position+16),compressed=archiveBytes.readUInt32LE(position+20);
        const size=archiveBytes.readUInt32LE(position+24),nameLength=archiveBytes.readUInt16LE(position+28);
        const extraLength=archiveBytes.readUInt16LE(position+30),commentLength=archiveBytes.readUInt16LE(position+32);
        const offset=archiveBytes.readUInt32LE(position+42),next=position+46+nameLength+extraLength+commentLength;
        if (next>end || flags&~0x0808 || ![0,8].includes(method) || [size,compressed,offset].includes(0xffffffff)
            || archiveBytes.readUInt16LE(position+34)!==0
            || (archiveBytes.readUInt32LE(position+38)>>>16&0xf000)===0xa000
            || size>limit || (total+=size)>limit
            || offset+30>directoryOffset || archiveBytes.readUInt32LE(offset)!==0x04034b50) {
            throw new Error('BLOCKED unsafe ZIP entry metadata.');
        }
        const nameBytes=archiveBytes.subarray(position+46,position+46+nameLength);
        const name=new TextDecoder('utf-8',{fatal:true}).decode(nameBytes);
        const localName=archiveBytes.readUInt16LE(offset+26),localExtra=archiveBytes.readUInt16LE(offset+28);
        const start=offset+30+localName+localExtra;
        if (!names.includes(name) || result.has(name) || localName!==nameLength
            || !archiveBytes.subarray(offset+30,offset+30+localName).equals(nameBytes)
            || archiveBytes.readUInt16LE(offset+6)!==flags || archiveBytes.readUInt16LE(offset+8)!==method
            || start+compressed>directoryOffset) throw new Error('BLOCKED ZIP names or local entry differ.');
        let finish=start+compressed;
        if (flags&8) {
            const signature=finish+4<=directoryOffset && archiveBytes.readUInt32LE(finish)===0x08074b50;
            const descriptor=finish+(signature?4:0);
            if (descriptor+12>directoryOffset || archiveBytes.readUInt32LE(descriptor)!==crc
                || archiveBytes.readUInt32LE(descriptor+4)!==compressed || archiveBytes.readUInt32LE(descriptor+8)!==size) {
                throw new Error('BLOCKED ZIP data descriptor differs.');
            }
            finish=descriptor+12;
        } else if (archiveBytes.readUInt32LE(offset+14)!==crc || archiveBytes.readUInt32LE(offset+18)!==compressed
            || archiveBytes.readUInt32LE(offset+22)!==size) throw new Error('BLOCKED ZIP local sizes or checksum differ.');
        const encoded=archiveBytes.subarray(start,start+compressed);
        const decoded=method===0?Buffer.from(encoded):inflateRawSync(encoded,{maxOutputLength:Math.max(1,size)});
        if (decoded.length!==size || zipCrc(decoded)!==crc) throw new Error('BLOCKED ZIP entry bytes differ.');
        result.set(name,decoded);ranges.push({start:offset,end:finish});position=next;
    }
    ranges.sort((left,right)=>left.start-right.start);
    if (position!==end || ranges[0].start!==0 || ranges.at(-1).end!==directoryOffset
        || ranges.some((range,index)=>index>0 && ranges[index-1].end!==range.start)) {
        throw new Error('BLOCKED ZIP contains overlapping, omitted or unlisted data.');
    }
    return result;
}
