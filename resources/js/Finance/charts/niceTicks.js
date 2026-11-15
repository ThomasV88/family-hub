// Integer "nice" ticks in minor units: step is 1, 2 or 5 times a power of ten. BigInt keeps the maths exact for
// the whole bounded money range, so a tick is never off by float rounding. The ticks cover [min, max] and include 0
// whenever the range crosses 0 (0 is a multiple of every step).
const MULTIPLIERS = [1n, 2n, 5n];

function floorDiv(a, b) {
    const q = a / b;
    return a % b !== 0n && (a < 0n) !== (b < 0n) ? q - 1n : q;
}

function* steps() {
    for (let power = 1n; ; power *= 10n) {
        for (const m of MULTIPLIERS) yield m * power;
    }
}

export function niceTicks(minMinor, maxMinor, count = 3) {
    let lo = BigInt(minMinor);
    let hi = BigInt(maxMinor);
    if (lo > hi) [lo, hi] = [hi, lo];
    if (lo === hi) hi = lo + 1n;
    const limit = BigInt(Math.max(2, count));
    for (const step of steps()) {
        const first = floorDiv(lo, step);
        const last = -floorDiv(-hi, step);
        if (last - first + 1n <= limit) {
            const out = [];
            for (let i = first; i <= last; i += 1n) out.push(Number(i * step));
            return out;
        }
    }
    return [];
}
