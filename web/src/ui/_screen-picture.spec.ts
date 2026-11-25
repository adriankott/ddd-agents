import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, it } from "vitest";

import {
  computePictureByteBudget,
  computePictureDimensions,
  computeRetryScale,
  convertPixelsToGrey,
  PICTURE_LIMITATIONS,
  prepareScreenCloneForPicture,
  SCREEN_PICTURE_PRESETS,
} from "./screen-picture";

describe('computePictureDimensions', () => {
  it('keeps a small source at its size', () => {
    assert.deepEqual(computePictureDimensions(800, 600, { pictureSize: 'medium', renderMode: 'colour' }), { width: 800, height: 600, scale: 1 });
  });

  it('keeps the long edge and the pixels of every preset', () => {
    for (const pictureSize of ['small', 'medium', 'large'] as const) {
      const preset = SCREEN_PICTURE_PRESETS[pictureSize];
      const dimensions = computePictureDimensions(1920, 6000, { pictureSize, renderMode: 'colour' });
      assert.ok(Math.max(dimensions.width, dimensions.height) <= preset.maxLongEdge, pictureSize);
      assert.ok(dimensions.width * dimensions.height <= preset.maxPixels, pictureSize);
    }
  });

  it('takes 75 % of the size in the machine mode', () => {
    const colour = computePictureDimensions(1600, 1000, { pictureSize: 'medium', renderMode: 'colour' });
    const machine = computePictureDimensions(1600, 1000, { pictureSize: 'medium', renderMode: 'machine' });
    assert.equal(machine.width, Math.floor(colour.width * 0.75));
  });

  it('applies a retry factor to the source size', () => {
    const first = computePictureDimensions(1000, 1000, { pictureSize: 'large', renderMode: 'colour' });
    const retry = computePictureDimensions(1000, 1000, { pictureSize: 'large', renderMode: 'colour' }, 0.5);
    assert.equal(retry.width, Math.floor(first.width * 0.5));
  });
});

describe('budgets and retries', () => {
  it('halves the byte budget in the machine mode and stays below the server cap', () => {
    assert.equal(computePictureByteBudget('medium', 'machine'), SCREEN_PICTURE_PRESETS.medium.maxBytes / 2);
    assert.ok(computePictureByteBudget('large', 'colour') <= 614_400);
  });

  it('shrinks the scale with the square root of the overshoot, at least by a tenth', () => {
    assert.ok(Math.abs(computeRetryScale(1, 400_000, 100_000) - 0.45) < 1e-9);
    assert.ok(computeRetryScale(1, 101_000, 100_000) <= 0.9);
    assert.equal(computeRetryScale(1, 100_500, 100_000), 0.9 * Math.sqrt(100_000 / 100_500));
  });
});

describe('convertPixelsToGrey', () => {
  it('turns colours grey and quantizes to the given levels', () => {
    const pixels = new Uint8ClampedArray([255, 0, 0, 255, 10, 200, 30, 255]);
    convertPixelsToGrey(pixels, 64);
    assert.equal(pixels[0], pixels[1]);
    assert.equal(pixels[1], pixels[2]);
    const levels = new Set(Array.from({ length: 64 }, (_, level) => Math.round((level * 255) / 63)));
    assert.ok(levels.has(pixels[4]));
    assert.equal(pixels[3], 255);
  });
});

describe('prepareScreenCloneForPicture', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('writes passwords as dots, leaves skipped elements out and blanks secret ones', () => {
    document.body.innerHTML =
      '<main><input type="password" id="pw"><input id="name"><div data-agent-skip>chat</div><p data-agent-secret>IBAN DE00</p><script>x()</script></main>';
    (document.getElementById('pw') as HTMLInputElement).value = 'hunter2';
    (document.getElementById('name') as HTMLInputElement).value = 'Ada';
    const clone = prepareScreenCloneForPicture(document.body, false);
    const html = (clone as HTMLElement).outerHTML;
    assert.ok(html.includes('value="•••••••"'));
    assert.ok(!html.includes('hunter2'));
    assert.ok(html.includes('value="Ada"'));
    assert.ok(!html.includes('chat'));
    assert.ok(!html.includes('IBAN'));
    assert.ok(!html.includes('x()'));
  });

  it('expands a scroll container', () => {
    document.body.innerHTML = '<div id="list" style="height:100px;overflow-y:auto"><p>row</p></div>';
    const list = document.getElementById('list') as HTMLElement;
    Object.defineProperty(list, 'scrollHeight', { value: 900 });
    Object.defineProperty(list, 'clientHeight', { value: 100 });
    const clone = prepareScreenCloneForPicture(document.body, false);
    const listClone = clone.querySelector('#list') as HTMLElement;
    assert.equal(listClone.style.overflowY, 'visible');
    assert.equal(listClone.style.height, 'auto');
  });
});

describe('PICTURE_LIMITATIONS', () => {
  it('names the same sentences as the server', () => {
    const packageFile = resolve(dirname(fileURLToPath(import.meta.url)), '../../../src/Presentation/Mcp/Client/Agents/AgentUIMCPPackage.php');
    const phpSource = readFileSync(packageFile, 'utf8');
    const block = /PICTURE_LIMITATIONS = \[([\s\S]*?)\];/.exec(phpSource)?.[1] ?? '';
    const serverSentences = Array.from(block.matchAll(/'((?:[^'\\]|\\.)*)'/g), match => match[1].replace(/\\'/g, "'"));
    assert.deepEqual(serverSentences, [...PICTURE_LIMITATIONS]);
  });
});
