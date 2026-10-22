import { test } from 'node:test';
import assert from 'node:assert/strict';
import { helper } from './extract.mjs';

const compactCount = helper('app.js', 'compactCount');
const barLabel = helper('app.js', 'barLabel');
const updatedLabel = helper('app.js', 'updatedLabel');

test('compactCount: same thresholds as Rankings::compact in PHP', () => {
  assert.equal(compactCount(0), '0');
  assert.equal(compactCount(9870), '9,870');
  assert.equal(compactCount(9999), '9,999');
  assert.equal(compactCount(10000), '10K');
  assert.equal(compactCount(845210), '845K');
  assert.equal(compactCount(999499), '999K');
  assert.equal(compactCount(999500), '1M');
  assert.equal(compactCount(2345678), '2.3M');
  assert.equal(compactCount(209140943), '209.1M');
  assert.equal(compactCount(999949999), '999.9M');
  assert.equal(compactCount(999950000), '1B');
  assert.equal(compactCount(1527871757), '1.5B');
});

test('barLabel: one decimal for each of White, Draw and Black', () => {
  assert.equal(barLabel(45.123, 30, 24.877), 'White 45.1%, Draw 30.0%, Black 24.9%');
  assert.equal(barLabel(0, 0, 0), 'White 0.0%, Draw 0.0%, Black 0.0%');
  assert.equal(barLabel(100, 0, 0), 'White 100.0%, Draw 0.0%, Black 0.0%');
});

test('updatedLabel: the cache timestamp as "Sep 25, 2026"; empty for missing or bad input', () => {
  assert.equal(updatedLabel('2026-09-25 19:55:16'), 'Sep 25, 2026');
  assert.equal(updatedLabel('2026-01-02 00:00:00'), 'Jan 2, 2026');
  assert.equal(updatedLabel(''), '');
  assert.equal(updatedLabel(null), '');
  assert.equal(updatedLabel(undefined), '');
  assert.equal(updatedLabel('not a date'), '');
});

test('helper: a missing function is an error, not undefined', () => {
  assert.throws(() => helper('app.js', 'noSuchFunction'), /no function or const named/);
});
