<template>
    <div class="auth-scene" aria-hidden="true">
        <svg
            class="auth-scene__svg"
            viewBox="0 0 1440 900"
            preserveAspectRatio="xMidYMid slice"
        >
            <defs>
                <radialGradient id="auth-canvas" cx="48%" cy="40%" r="72%">
                    <stop offset="0%" class="auth-scene__stop-0" />
                    <stop offset="48%" class="auth-scene__stop-1" />
                    <stop offset="100%" class="auth-scene__stop-2" />
                </radialGradient>
            </defs>

            <rect width="1440" height="900" fill="url(#auth-canvas)" />

            <path
                class="auth-scene__swell"
                d="M1440 250 C1360 310 1310 430 1348 560 C1378 662 1412 760 1440 820 V250 Z"
            />

            <path
                class="auth-scene__arc auth-scene__arc-1"
                stroke-width="1.6"
                stroke-linecap="round"
                d="M1128 0 C1248 28 1378 86 1440 214"
            />
            <path
                class="auth-scene__arc auth-scene__arc-2"
                stroke-width="1.5"
                stroke-linecap="round"
                d="M1204 0 C1300 24 1388 74 1440 176"
            />
            <path
                class="auth-scene__arc auth-scene__arc-3"
                stroke-width="1.4"
                stroke-linecap="round"
                d="M1276 0 C1348 20 1400 62 1440 138"
            />
            <path
                class="auth-scene__arc auth-scene__arc-4"
                stroke-width="1.3"
                stroke-linecap="round"
                d="M1344 0 C1390 16 1420 48 1440 102"
            />

            <path
                class="auth-scene__wave-1"
                d="M0 900 V638 C168 596 262 698 458 676 C700 650 858 568 1096 552 C1278 540 1372 492 1440 458 V900 Z"
            />
            <path
                class="auth-scene__wave-2"
                d="M0 900 V688 C154 652 258 744 452 724 C688 702 854 634 1074 622 C1262 612 1368 564 1440 536 V900 Z"
            />
            <path
                class="auth-scene__wave-3"
                d="M0 900 V736 C146 706 252 788 446 772 C678 754 848 702 1056 698 C1250 694 1364 650 1440 630 V900 Z"
            />
            <path
                class="auth-scene__wave-4"
                d="M0 900 V778 C140 754 248 824 440 814 C670 802 842 762 1040 766 C1240 770 1360 732 1440 720 V900 Z"
            />
            <path
                class="auth-scene__wave-5"
                d="M0 900 V818 C134 800 244 858 434 852 C662 844 836 816 1026 824 C1230 832 1356 802 1440 796 V900 Z"
            />

            <path
                class="auth-scene__foam auth-scene__foam-1"
                stroke-width="2.2"
                stroke-linecap="round"
                d="M0 704 C160 668 264 758 458 738 C690 716 854 650 1072 638 C1260 628 1366 582 1440 556"
            />
            <path
                class="auth-scene__foam auth-scene__foam-2"
                stroke-width="1.7"
                stroke-linecap="round"
                d="M0 752 C148 722 254 802 448 786 C676 768 846 718 1054 714 C1248 710 1362 668 1440 648"
            />
            <path
                class="auth-scene__foam auth-scene__foam-3"
                stroke-width="1.5"
                stroke-linecap="round"
                d="M0 796 C138 772 248 840 440 830 C668 818 840 778 1038 782 C1236 786 1358 750 1440 738"
            />
        </svg>
    </div>
</template>

<style scoped>
.auth-scene {
    --scene-stop-0: #c5e4f7;
    --scene-stop-1: #b4daf2;
    --scene-stop-2: #8fc8ea;
    --scene-swell: #d7eefb;
    --scene-arc-1: #9ec9ea;
    --scene-arc-2: #b7daf3;
    --scene-arc-3: #c7e4f6;
    --scene-arc-4: #d5edfa;
    --scene-wave-1: #e8f5fc;
    --scene-wave-2: #c8e7f8;
    --scene-wave-3: #8ec8f0;
    --scene-wave-4: #4aa4e8;
    --scene-wave-5: #1b7ad8;
    --scene-foam-1: #ffffff;
    --scene-foam-2: #eaf7ff;
    --scene-foam-3: #d7effc;
    pointer-events: none;
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
}

html.dark .auth-scene {
    --scene-stop-0: #10263c;
    --scene-stop-1: #0b1b2d;
    --scene-stop-2: #06111e;
    --scene-swell: #0f2438;
    --scene-arc-1: #1f4b70;
    --scene-arc-2: #1b4264;
    --scene-arc-3: #183a58;
    --scene-arc-4: #15334e;
    --scene-wave-1: #0d2134;
    --scene-wave-2: #102a43;
    --scene-wave-3: #143857;
    --scene-wave-4: #18507e;
    --scene-wave-5: #1a64a8;
    --scene-foam-1: #5f9fd3;
    --scene-foam-2: #4f8cc0;
    --scene-foam-3: #417aab;
}

.auth-scene__svg {
    display: block;
    width: 100%;
    height: 100%;
}

.auth-scene__stop-0 {
    stop-color: var(--scene-stop-0);
}

.auth-scene__stop-1 {
    stop-color: var(--scene-stop-1);
}

.auth-scene__stop-2 {
    stop-color: var(--scene-stop-2);
}

.auth-scene__swell {
    fill: var(--scene-swell);
}

.auth-scene__arc,
.auth-scene__foam {
    fill: none;
}

.auth-scene__arc-1 {
    stroke: var(--scene-arc-1);
    opacity: 0.55;
}

.auth-scene__arc-2 {
    stroke: var(--scene-arc-2);
    opacity: 0.62;
}

.auth-scene__arc-3 {
    stroke: var(--scene-arc-3);
    opacity: 0.7;
}

.auth-scene__arc-4 {
    stroke: var(--scene-arc-4);
    opacity: 0.8;
}

.auth-scene__wave-1 {
    fill: var(--scene-wave-1);
}

.auth-scene__wave-2 {
    fill: var(--scene-wave-2);
}

.auth-scene__wave-3 {
    fill: var(--scene-wave-3);
}

.auth-scene__wave-4 {
    fill: var(--scene-wave-4);
}

.auth-scene__wave-5 {
    fill: var(--scene-wave-5);
}

.auth-scene__foam-1 {
    stroke: var(--scene-foam-1);
    opacity: 0.78;
}

.auth-scene__foam-2 {
    stroke: var(--scene-foam-2);
    opacity: 0.7;
}

.auth-scene__foam-3 {
    stroke: var(--scene-foam-3);
    opacity: 0.55;
}
</style>
