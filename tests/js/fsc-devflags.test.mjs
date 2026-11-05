// Tests for resources/assets/fsc-devflags.js (display flags of the dev panel). Run with: bun test packages/content-sdk-php/tests/js
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/fsc-devflags.js'), 'utf8');
const exported = { exports: {} };
new Function('module', source)(exported);
const flags = exported.exports;

function fakeWindow() {
    const appended = [];
    const attrs = {};
    const win = {
        navigator: {},
        matchMedia: (query) => ({ matches: false, media: query, real: true }),
        HTMLCanvasElement: { prototype: { getContext: (type) => ({ type }) } },
        document: {
            head: { append: (node) => appended.push(node) },
            documentElement: { setAttribute: (name, value) => { attrs[name] = value; } },
            createElement: () => ({ setAttribute() {}, textContent: '' }),
        },
    };
    return { win, appended, attrs };
}

describe('parse', () => {
    it('keeps well-formed names (a site may add its own) and drops the rest', () => {
        expect(flags.parse('reduce,phone,Bad Name,,x'.replace('x', 'nogl'))).toEqual({ reduce: true, phone: true, nogl: true });
        expect(flags.parse('')).toEqual({});
        expect(flags.parse(null)).toEqual({});
    });
});

describe('forced', () => {
    it('answers both motion queries only when reduce is on', () => {
        expect(flags.forced('(prefers-reduced-motion: reduce)', { reduce: true })).toBe(true);
        expect(flags.forced('(prefers-reduced-motion:no-preference)', { reduce: true })).toBe(false);
        expect(flags.forced('(prefers-reduced-motion: reduce)', {})).toBe(null);
        expect(flags.forced('(min-width: 800px)', { reduce: true })).toBe(null);
    });
});

describe('apply', () => {
    it('follows the flags, sets the html attributes and window.fscDev', () => {
        const { win, appended, attrs } = fakeWindow();
        flags.apply(win, flags.parse('reduce,save,weak,nogl,phone'));

        expect(win.matchMedia('(prefers-reduced-motion: reduce)').matches).toBe(true);
        expect(win.matchMedia('(min-width: 800px)').real).toBe(true);
        expect(win.navigator.connection.saveData).toBe(true);
        expect(win.navigator.deviceMemory).toBe(1);
        expect(win.navigator.hardwareConcurrency).toBe(2);
        expect(win.HTMLCanvasElement.prototype.getContext('webgl2')).toBe(null);
        expect(win.HTMLCanvasElement.prototype.getContext('experimental-webgl')).toBe(null);
        expect(win.HTMLCanvasElement.prototype.getContext('2d')).toEqual({ type: '2d' });
        expect(attrs).toEqual({ 'data-fsc-motion': 'reduce', 'data-fsc-save-data': '', 'data-fsc-no-webgl': '', 'data-fsc-weak': '' });
        expect(appended.length).toBe(1);
        expect(win.fscDev.has('reduce')).toBe(true);
        expect(win.fscDev.has('phone')).toBe(true);
        expect(win.fscDev.has('other')).toBe(false);
    });

    it('leaves the window untouched without flags', () => {
        const { win, appended, attrs } = fakeWindow();
        const before = win.matchMedia;
        flags.apply(win, {});

        expect(win.matchMedia).toBe(before);
        expect('connection' in win.navigator).toBe(false);
        expect(appended.length).toBe(0);
        expect(attrs).toEqual({});
        expect(win.fscDev.flags).toEqual([]);
    });
});
