import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

globalThis.window = globalThis;
globalThis.document = { addEventListener() {} };
await import('../public/js/room-preview-engine.js');

const geometry = globalThis.RoomPreviewEngineTest;
test('door fitting uses the same strict room-facing corner contract as the server', () => {
    const cases=JSON.parse(readFileSync(new URL('./Fixtures/doorway-fit-quads.json',import.meta.url),'utf8'));
    for(const sample of cases) assert.equal(Boolean(geometry.doorQuad(sample.quad)),sample.valid,sample.name);
});
test('an alpha-empty image has no usable crop or invented product bounds', () => {
    assert.equal(geometry.imageContentBounds(new Uint8ClampedArray(16),2,2),null);
});
test('an undrawable product never reports success, enables export, or starts paid work', async () => {
    const engine=startingEngine(); engine.retryAiPreview=true; engine.draw=()=>false;
    let reported=0; engine.report=()=>reported++;
    await engine.start();
    assert.equal(engine.result,null); assert.equal(engine.download.disabled,true);
    assert.equal(engine.frame.dataset.finalPlacement,''); assert.equal(reported,0);
    assert.equal(engine.enhancedCalls+engine.verificationCalls,0);
});
test('a late legacy visual check cannot overwrite the current product result', async () => {
    const previous=globalThis.fetch; let complete;
    globalThis.fetch=()=>new Promise(resolve=>{complete=resolve;});
    const engine=startingEngine(); const old={mode:'floor',x:.2}; const current={mode:'floor',x:.8};
    engine.result=old; engine.canvas.toDataURL=()=> 'data:image/jpeg;base64,AAAA';
    engine.frame.dataset.verifyUrl='/check'; engine.verifyLoop=RoomPreviewEngine.prototype.verifyLoop;
    try {
        const pending=engine.verifyLoop({},0); engine.runId++; engine.result=current;
        complete({ok:true,json:async()=>({valid:true,verdict:'good',realism_score:.9})}); await pending;
        assert.equal(engine.result,current); assert.equal(engine.verificationScore,undefined);
    } finally {globalThis.fetch=previous;}
});
test('one optional visual check never follows corrections with additional paid requests', async () => {
    const previous=globalThis.fetch; let calls=0;
    globalThis.fetch=async()=>{calls++;return {ok:true,json:async()=>({valid:true,verdict:'needs_adjustment',realism_score:.4,corrections:{dx:.01,scale_factor:.95}})};};
    const original={mode:'floor',x:.5,y:.8,width:.3,height:.3,depth:.1,scale:1};
    const engine=Object.create(globalThis.RoomPreviewEngine.prototype);
    Object.assign(engine,{result:original,runId:1,frame:{dataset:{verifyUrl:'/check',productId:'1'}},
        canvas:{toDataURL:()=> 'data:image/jpeg;base64,AAAA'},warnings:[],setProgress(){},report(){},draw(){},hardValid:()=>true});
    try {await engine.verifyLoop({},1);assert.equal(calls,1);assert.equal(engine.result,original);assert.equal(engine.verificationScore,.4);}
    finally {globalThis.fetch=previous;}
});
test('room lighting stays neutral without reliable samples and gently follows warm directional light', () => {
    assert.deepEqual(geometry.lightingProfile([]),[1,0,0]);
    assert.deepEqual(geometry.lightingColor([90,120,160],[1,0,0],.5),[90,120,160]);
    const samples=Array.from({length:48},(_,i)=>i%8<4 ? [100,90,80,.25] : [190,180,170,.75]);
    const profile=geometry.lightingProfile(samples);
    assert.ok(Math.abs(profile[0]-.9858666666666668)<1e-9, 'same room profile as the saved renderer');
    assert.ok(profile[1]>0 && profile[2]>0);
    const left=geometry.lightingColor([120,120,120],profile,0),right=geometry.lightingColor([120,120,120],profile,1);
    assert.ok(left[0]>left[2] && right[0]>left[0]);
    assert.deepEqual(geometry.lightingColor([255,255,255],profile,.5),[255,255,255]);
    assert.ok(left.every(channel=>Math.abs(channel-120)<20));
});

test('perspective uses a bounded near-far projection without leaning the centre or moving floor contacts', () => {
    const placement = {x:.5,y:.8,width:.2,height:.4,perspectiveDeg:24};
    const quad = geometry.furnitureQuad(placement,200,200);
    const near = Math.hypot(quad[0][0]-quad[3][0],quad[0][1]-quad[3][1]);
    const far = Math.hypot(quad[1][0]-quad[2][0],quad[1][1]-quad[2][1]);
    assert.ok(near > far && near/far < 1.2, 'turn remains subtle and preserves the photographed shape');
    assert.deepEqual(geometry.furnitureQuad({...placement,perspectiveDeg:1000},200,200),quad);
    for (const [u,v,point] of [[0,0,quad[0]],[1,0,quad[1]],[1,1,quad[2]],[0,1,quad[3]],[.5,1,[100,160]],[.5,0,[100,80]]]) {
        geometry.projectQuad(quad,u,v).forEach((value,index)=>assert.ok(Math.abs(value-point[index]) < 1e-7));
    }
    const reversed = geometry.furnitureQuad({...placement,perspectiveDeg:-24},200,200);
    assert.ok(reversed[0][1] > reversed[1][1], 'opposite angles reverse the nearer side');
});

test('visual sizing keeps tall furniture modest and never enlarges small items', () => {
    const fixture = JSON.parse(readFileSync(new URL('./Fixtures/room-preview-scale.json', import.meta.url), 'utf8'));
    for (const sample of fixture.cases) {
        const engine = photoEngine();
        Object.assign(engine, {scalePolicy: fixture.policy, itemType: sample.type, floorDetected: sample.floor,
            room: {naturalWidth: sample.frame * 1000, naturalHeight: 1000}});
        assert.ok(Math.abs(engine.boundedVisualWidth(sample.requested, sample.aspect) - sample.expected) < 1e-9, sample.type);
    }
});

test('browser projection matches saved-image geometry for rotation, shear, tall items and frame edges', () => {
    const cases = JSON.parse(readFileSync(new URL('./Fixtures/room-preview-geometry.json', import.meta.url), 'utf8').replace(/^\uFEFF/, ''));
    for (const sample of cases) {
        const [width, height] = sample.room, [itemWidth, itemHeight] = sample.item, p = sample.placement;
        const quad = geometry.furnitureQuad({x:p.x_percent/100, y:p.y_percent/100, width:p.max_width_percent/100,
            height:p.max_width_percent/100*width/height*itemHeight/itemWidth,
            rotationDeg:p.rotation_deg, perspectiveDeg:p.perspective_deg}, width, height);
        quad.flat().forEach((coordinate, i) => assert.ok(Math.abs(coordinate-sample.quad.flat()[i]) < 1e-7, sample.name));
    }
});

test('furniture draws at full opacity while retaining its original cutout', () => {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    const item = {naturalWidth: 20, naturalHeight: 40};
    const calls = [];
    engine.context = {
        clearRect() {}, save() {}, restore() {}, translate() {}, rotate() {}, transform() {}, scale() {},
        drawImage(image, ...args) { calls.push({image, args, alpha: this.globalAlpha}); },
    };
    Object.assign(engine, {
        room: {}, item, analysis: {},
        productImageBounds: {x: 0, y: 0, width: 20, height: 40},
        canvasSize: () => ({width: 200, height: 200}),
        dimensionsFromCatalog: () => true,
        drawContactShadows() {},
    });
    const previous = document.createElement;
    const layerContext = {...engine.context, getImageData: () => ({data: new Uint8ClampedArray(200*200*4).fill(255)})};
    document.createElement = () => ({getContext: () => layerContext});
    try { assert.equal(engine.draw({mode: 'floor', x: .5, y: .8, width: .2, height: .4}), true); }
    finally { document.createElement = previous; }
    const furniture = calls.find((call) => call.image === item);
    assert.equal(furniture.alpha, 1, 'solid source pixels must hide the room behind them');
    assert.deepEqual(furniture.args.slice(0, 4), [0, 0, 20, 40], 'draw the original alpha cutout');
});

test('contact regions identify separate feet at different depths and ignore faint fringe', () => {
    const pixels = new Uint8ClampedArray(40 * 40 * 4);
    const fill = (left, top, right, bottom, alpha = 255) => {
        for (let y = top; y <= bottom; y++) for (let x = left; x <= right; x++) pixels[(y * 40 + x) * 4 + 3] = alpha;
    };
    fill(5, 10, 34, 22); fill(5, 22, 9, 33); fill(30, 22, 34, 38);
    fill(16, 35, 20, 39, 60);
    const contacts = geometry.alphaContactRegions(pixels, 40, 40);
    assert.equal(contacts.length, 2);
    assert.deepEqual(contacts.map((c) => c.y), [39 / 40, 34 / 40]);
    assert.ok(contacts.every((c) => c.x < .3 || c.x > .7));
    assert.deepEqual(geometry.alphaContactRegions(new Uint8ClampedArray(40 * 40 * 4), 40, 40), []);
});

test('point-in-polygon accepts floor points and rejects points outside', () => {
    const floor = [[0.1, 0.4], [0.9, 0.4], [0.9, 0.95], [0.1, 0.95]];
    assert.equal(geometry.pointInPolygon(0.5, 0.7, floor), true);
    assert.equal(geometry.pointInPolygon(0.5, 0.2, floor), false);
});

test('an empty vision floor polygon falls back to the valid legacy photo polygon', () => {
    const legacyFloor = [[10, 40], [90, 40], [95, 95], [5, 95]];
    assert.deepEqual(geometry.detectedFloorPolygon({floor_polygon: []}, {floor_polygon: legacyFloor}), [
        [0.1, 0.4], [0.9, 0.4], [0.95, 0.95], [0.05, 0.95],
    ]);
});

test('polygon area is stable for normalized room geometry', () => {
    assert.equal(geometry.polygonArea([[0, 0], [1, 0], [1, 1], [0, 1]]), 1);
});

test('bounds and overlap checks identify furniture collisions', () => {
    const box = geometry.bounds([[0.2, 0.3], [0.7, 0.3], [0.7, 0.8], [0.2, 0.8]]);
    assert.deepEqual(box, { minX: 0.2, maxX: 0.7, minY: 0.3, maxY: 0.8 });
    assert.equal(geometry.rectanglesOverlap({ left: 0.2, right: 0.5, top: 0.2, bottom: 0.5 }, { left: 0.4, right: 0.7, top: 0.4, bottom: 0.7 }), true);
    assert.equal(geometry.rectanglesOverlap({ left: 0.1, right: 0.2, top: 0.1, bottom: 0.2 }, { left: 0.4, right: 0.7, top: 0.4, bottom: 0.7 }), false);
});
test('blocked polygons contain furniture even when every corner is inside the obstacle', () => {
    const obstacle = [[0.2, 0.2], [0.8, 0.2], [0.8, 0.8], [0.2, 0.8]];
    assert.equal(geometry.polygonIntersectsRect(obstacle, { left: 0.4, right: 0.6, top: 0.4, bottom: 0.6 }), true);
    assert.equal(geometry.polygonIntersectsRect(obstacle, { left: 0.85, right: 0.95, top: 0.4, bottom: 0.6 }), false);
});
test('polygon intersection does not treat disjoint edge extensions as collisions', () => {
    const obstacle = [[0.1, 0.2], [0.2, 0.2], [0.2, 0.8], [0.1, 0.8]];
    assert.equal(geometry.polygonIntersectsRect(obstacle, { left: 0.8, right: 0.9, top: 0.4, bottom: 0.6 }), false);
});
test('room preview converts bbox-only blocked regions into normalized polygons', () => {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    assert.deepEqual(engine.regionPolygon({ bbox: [20, 30, 40, 50] }), [[0.2, 0.3], [0.4, 0.3], [0.4, 0.5], [0.2, 0.5]]);
});
test('door fallback selects the most compatible validated opening instead of the first match', () => {
    const engine = photoEngine();
    const first = { type: 'door', confidence: 'high', label: 'front doorway', door_fit_quad_percent: [[0.1, 0.1], [0.4, 0.1], [0.4, 0.3], [0.1, 0.3]] };
    const compatible = { type: 'door', confidence: 'medium', label: 'side doorway', door_fit_quad_percent: [[0.6, 0.2], [0.7, 0.2], [0.7, 0.48], [0.6, 0.48]] };
    engine.openings = [first, compatible];

    assert.equal(engine.openingForDoor({width: 90, height: 210}), compatible);
});
test('door fallback ignores window and bbox-only opening detections', () => {
    const engine = photoEngine();
    engine.openings = [
        { type: 'window', label: 'window', door_fit_quad_percent: [[0.2, 0.2], [0.4, 0.2], [0.4, 0.5], [0.2, 0.5]] },
        { type: 'door', label: 'doorway', bbox: [0.6, 0.2, 0.8, 0.8] },
    ];

    assert.equal(engine.openingForDoor({width: 90, height: 210}), null);
});
test('scale clues use a reliability-weighted median and ignore a low-confidence outlier', () => {
    assert.equal(geometry.weightedMedian([
        { scale: 10, reliability: 0.8 },
        { scale: 11, reliability: 0.2 },
        { scale: 80, reliability: 0.01 },
    ]), 10);
});
test('footprints crossing a concave floor edge are rejected even when some corners are inside', () => {
    const concaveFloor = [[0.1,0.4],[0.9,0.4],[0.9,0.9],[0.6,0.9],[0.6,0.65],[0.4,0.65],[0.4,0.9],[0.1,0.9]];
    const crossingFootprint = [[0.35,0.7],[0.65,0.7],[0.65,0.82],[0.35,0.82]];
    const safeFootprint = [[0.15,0.7],[0.35,0.7],[0.35,0.82],[0.15,0.82]];
    assert.equal(geometry.polygonInsidePolygon(crossingFootprint, concaveFloor), false);
    assert.equal(geometry.polygonInsidePolygon(safeFootprint, concaveFloor), true);
});
function photoEngine(roomWidth = 0, imageWidth = 1200, imageHeight = 800) {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    Object.assign(engine, { frame: { dataset: { roomWidth } }, room: { naturalWidth: imageWidth, naturalHeight: imageHeight }, item: { naturalWidth: 600, naturalHeight: 300 }, floor: [[0.17,0.58],[0.83,0.58],[0.99,0.99],[0.01,0.99]], horizon: 0.4, analysis: {scale_clues:[{object:'door',pixel_height:420,typical_real_cm:210,base_y:0.9,reliability:0.9}]}, vision: {}, blocked: [], existing: [], openings: [], warnings: [], placementMode: 'floor' });
    return engine;
}

test('photo-only sizing ignores customer room measurements', () => {
    const dims = {width:225, depth:90, height:92};
    const a = photoEngine(2).autoFit(dims);
    const b = photoEngine(50).autoFit(dims);
    assert.deepEqual(a, b);
    assert.ok(a.width > 0.2, 'sala set must not be a tiny icon');
    assert.ok(a.y > 0.7, 'furniture should be grounded in the visible floor');
});

test('furniture keeps its pixel aspect ratio in landscape and portrait rooms', () => {
    for (const [w,h] of [[1200,800],[800,1200]]) {
        const engine = photoEngine(0,w,h);
        const p = engine.autoFit({width:225,depth:90,height:92});
        assert.ok(p);
        assert.ok(Math.abs((p.width*w)/(p.height*h)-2) < 1e-8);
        assert.ok(p.x-p.width/2 >= 0.02 && p.x+p.width/2 <= 0.98);
        assert.ok(p.y-p.height >= 0.02 && p.y <= 0.98);
    }
});

test('a local fallback cannot fill most of the room when no floor was detected', () => {
    for (const [w, h] of [[800, 534], [600, 900]]) {
        const engine = photoEngine(0, w, h);
        engine.floorDetected = false;
        engine.item = {naturalWidth: 600, naturalHeight: 500};
        Object.assign(engine.frame.dataset, {
            initialX: '64', initialY: '88', initialWidth: '68',
            placementRenderable: 'true', placementSurface: 'floor', placementSource: 'type-fallback',
        });
        const placement = engine.autoFit({width: 220, depth: 88, height: 92});
        assert.ok(placement);
        assert.ok(placement.width <= 0.5, 'fallback must leave visible room around the set');
        assert.ok(placement.height <= 0.45 + 1e-8, 'fallback must not tower over the room');
        assert.ok(placement.y >= 0.8, 'the bottom of the cutout stays on the lower floor area');
        assert.ok(engine.placementFitsPhoto(placement));
    }
});

test('browser preview uses the server-selected placement instead of ranking a second location', () => {
    const engine = photoEngine();
    Object.assign(engine.frame.dataset, {
        initialX: '72', initialY: '89', initialWidth: '30', initialQuad: 'null',
        placementRenderable: 'true', placementSurface: 'floor', placementConfidence: 'high',
        placementSource: 'candidate-zone', placementScore: '84', placementReason: 'Clear area near the living-room wall.',
        placementFlip: 'true', placementRotation: '-5', placementPerspective: '8',
    });

    const placement = engine.autoFit({width: 225, depth: 90, height: 92});

    assert.equal(placement.x, 0.72);
    assert.equal(placement.y, 0.89);
    assert.ok(placement.width <= 0.3);
    assert.equal(placement.source, 'candidate-zone');
    assert.equal(placement.reason, 'Clear area near the living-room wall.');
    assert.equal(placement.rotationDeg, -5);
    assert.equal(placement.perspectiveDeg, 8);
    assert.equal(placement.flip, true);
});

test('browser preview searches the actual photo floor when the server position is outside the image', () => {
    const engine = photoEngine();
    Object.assign(engine.frame.dataset, {
        initialX: '99', initialY: '86', initialWidth: '20', initialQuad: 'null',
        placementRenderable: 'true', placementSurface: 'floor', placementSource: 'candidate-zone',
    });

    const placement = engine.autoFit({width: 225, depth: 90, height: 92});

    assert.ok(placement, 'a locally searched photo fit should replace the out-of-frame suggestion');
    assert.notEqual(placement.x, 0.99);
    assert.ok(engine.placementFitsPhoto(placement));
});

test('browser preview keeps furniture unplaced when server geometry is blocked', () => {
    const engine = photoEngine();
    engine.analysis = {};
    engine.vision = {};
    Object.assign(engine.frame.dataset, {
        initialX: '50', initialY: '86', initialWidth: '30', initialQuad: 'null',
        placementRenderable: 'false', placementSurface: 'floor', placementSource: 'placement-blocked',
    });

    const placement = engine.autoFit({width: 140, depth: 80, height: 75});

    assert.equal(placement, null, 'the browser must not invent a placement after the server rejects the geometry');
});

test('browser preview does not invent a placement when the server marks it unavailable', () => {
    const engine = photoEngine();
    Object.assign(engine.frame.dataset, {
        initialX: '50', initialY: '86', initialWidth: '30', initialQuad: 'null',
        placementRenderable: 'false', placementSurface: 'opening', placementSource: 'placement-blocked',
    });
    engine.placementMode = 'door';

    assert.equal(engine.autoFit({width: 90, depth: 12, height: 210}), null);
});

test('floor-standing storage defaults to floor placement while explicit wall mounts stay on the wall', () => {
    const engine = photoEngine();

    for (const type of ['cabinet', 'wardrobe', 'shelf', 'storage cabinet']) {
        assert.equal(engine.placementModeFromType(type), 'floor', `${type} should use floor placement by default`);
    }
    assert.equal(engine.placementModeFromType('wall-mounted shelf'), 'wall');
    assert.equal(engine.placementModeFromType('floating cabinet'), 'wall');
});

test('server wall-floor surface keeps cabinets grounded on the floor', () => {
    const engine = photoEngine();
    engine.frame.dataset.placementSurface = 'wall-floor';
    engine.itemType = 'cabinet';

    assert.equal(engine.placementModeFromServer(), 'floor');
});

test('visual zone width preserves the product image aspect without relying on catalogue height', () => {
    const engine = photoEngine(0, 1200, 800);
    engine.item.naturalWidth = 300;
    engine.item.naturalHeight = 600;
    Object.assign(engine.frame.dataset, {
        initialX: '50', initialY: '86', initialWidth: '90', initialQuad: 'null',
        placementRenderable: 'true', placementSurface: 'floor',
    });

    const tallItem = engine.autoFit({width: 100, depth: 60, height: 180});
    const shortItem = engine.autoFit({width: 100, depth: 60, height: 60});
    const pixelAspect = (tallItem.width * engine.room.naturalWidth) / (tallItem.height * engine.room.naturalHeight);

    assert.equal(shortItem.width, tallItem.width, 'the clear photo zone controls visual width');
    assert.ok(Math.abs(pixelAspect - (engine.item.naturalWidth / engine.item.naturalHeight)) < 1e-8, 'the catalog image aspect ratio must remain unchanged');
});

test('transparent product-image margins are removed from the measured display bounds', () => {
    const width = 5;
    const height = 4;
    const pixels = new Uint8ClampedArray(width * height * 4);
    for (let y = 1; y <= 2; y += 1) {
        for (let x = 1; x <= 3; x += 1) pixels[(y * width + x) * 4 + 3] = 255;
    }

    assert.deepEqual(geometry.imageContentBounds(pixels, width, height, 500, 400), {
        x: 100, y: 100, width: 300, height: 200,
    });
});

test('placement confidence is bounded by both room and selected-zone confidence', () => {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    engine.roomConfidence = 0.8;
    engine.frame = {dataset: {}};

    assert.equal(engine.placementConfidenceFor('high'), 0.8);
    assert.equal(engine.placementConfidenceFor('low'), 0.42);
    assert.equal(geometry.confidenceScore(0), 0, 'a measured zero confidence must not become a default estimate');
});

test('AI visual score is reported separately from placement confidence', () => {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    Object.assign(engine, {
        placementConfidence: 0.38,
        verificationScore: 0.97,
        warnings: [],
        floorDetected: true,
        openings: [],
        frame: {dataset: {}},
        status: {},
        guidance: {textContent: ''},
        detections: {innerHTML: ''},
        setStatus(text, level) { this.status.textContent = text; this.status.level = level; },
    });

    engine.report({mode: 'floor', x: 0.5, reason: 'Clear floor zone.'});

    assert.equal(engine.status.textContent, 'Rough estimate');
    assert.match(engine.detections.innerHTML, /AI visual score · 97%/);
});

test('photo scale uses a medium or high AI room-width estimate but ignores owner measurements', () => {
    const engine = photoEngine();
    engine.analysis = {gemini_used: true, estimated_width_m: 4, dimension_confidence: 'medium'};
    const estimatedScale = engine.estimateScale(0.8);
    assert.equal(estimatedScale, engine.room.naturalWidth * engine.floorWidthAt(0.8) / 400,
        'the detected floor width already accounts for perspective, so it must not be scaled twice');
    assert.equal(engine.scaleBasis, 'photo-width-estimate');

    engine.analysis = {gemini_used: true, estimated_width_m: 4, dimension_confidence: 'customer-provided'};
    assert.equal(engine.estimateScale(0.8), null);
    engine.analysis = {gemini_used: true, estimated_width_m: 4, dimension_confidence: 'low'};
    assert.equal(engine.estimateScale(0.8), null);
});

test('conflicting single-object scale clues are rejected instead of selecting one arbitrarily', () => {
    const engine = photoEngine();
    engine.analysis = {};
    engine.vision = {scale_clues: [
        {object: 'door', pixel_height: 200, typical_real_cm: 200, base_y: 0.8, reliability: 0.9},
        {object: 'window', pixel_height: 400, typical_real_cm: 200, base_y: 0.8, reliability: 0.9},
    ]};

    assert.equal(engine.estimateScale(0.8), null);
    assert.equal(engine.scaleBasis, null);
});

test('without photo scale clues, preview uses the selected photo zone as an unverified visual estimate', () => {
    const engine = photoEngine();
    engine.analysis = {};
    engine.vision = {};
    Object.assign(engine.frame.dataset, {
        initialX: '50', initialY: '86', initialWidth: '30', initialQuad: 'null',
        placementRenderable: 'true', placementSurface: 'floor',
    });

    const placement = engine.autoFit({width: 225, depth: 90, height: 92});

    assert.equal(placement.scaleBasis, 'server-zone-visual-fit');
    assert.equal(placement.width, 0.3);
    assert.equal(engine.estimateScale(0.8), null);
});

test('automatic fitting never forces furniture into a fully blocked floor', () => {
    const engine = photoEngine();
    engine.blocked = [[[0, 0], [1, 0], [1, 1], [0, 1]]];

    assert.equal(engine.autoFit({width: 140, depth: 80, height: 75}), null);
});

test('sofa keeps the server zone in front of a wall and window when its footprint is clear', () => {
    const engine = photoEngine(0, 800, 534);
    engine.item = {naturalWidth: 600, naturalHeight: 500};
    engine.floorDetected = true;
    engine.floor = [[0,.61],[.28,.66],[1,.66],[1,1],[0,1]];
    engine.blocked = [[[.59,.23],[.98,.23],[.98,.55],[.59,.55]], [[.58,.55],[1,.55],[1,.66],[.58,.66]]];
    Object.assign(engine.frame.dataset, {
        initialX: '62', initialY: '80', initialWidth: '42', placementRenderable: 'true',
        placementSurface: 'floor', placementSource: 'candidate-zone',
    });
    const result = engine.autoFit({width:220, depth:88, height:92});
    assert.equal(result.x, .62);
    assert.equal(result.y, .8);
    assert.equal(result.width, .42);
    assert.equal(result.depth, .126);
    assert.ok(engine.hardValid(result, {width:220, depth:88, height:92}));
});

test('doorway occlusion uses visible furniture cells instead of transparent image corners', () => {
    const engine = photoEngine();
    engine.blocked = [];
    engine.bodyBlocked = [[[.3,.3],[.4,.3],[.4,.5],[.3,.5]]];
    const placement = {mode:'floor', x:.5, y:.9, width:.5, height:.6, depth:.15};
    engine.productCollisionCells = [{left:.5, top:0, right:1, bottom:1}];
    assert.equal(engine.placementFitsPhoto(placement), true, 'transparent left half may overlap the doorway');
    engine.productCollisionCells = [{left:0, top:0, right:1, bottom:1}];
    assert.equal(engine.placementFitsPhoto(placement), false, 'visible furniture must not cover the doorway');
    assert.equal(engine.hardValid(placement, {width:220,depth:88,height:92}), false);
});

test('walkways and door swing remain blocked even below transparent product pixels', () => {
    const engine = photoEngine();
    engine.productCollisionCells = [{left:.5, top:0, right:1, bottom:1}];
    engine.blocked = [[[.3,.78],[.4,.78],[.4,.95],[.3,.95]]];
    const placement = {mode:'floor', x:.5, y:.9, width:.5, height:.6, depth:.15};
    assert.equal(engine.placementFitsPhoto(placement), false);
    assert.equal(engine.hardValid(placement, {width:220,depth:88,height:92}), false);
});

test('existing furniture protects both its floor footprint and visible upper body', () => {
    const engine = photoEngine();
    const placement = {mode:'floor', x:.5, y:.9, width:.5, height:.6, depth:.15};
    engine.productCollisionCells = [{left:.5, top:0, right:1, bottom:1}];
    engine.existing = [{left:.3, top:.8, right:.4, bottom:.95}];
    assert.equal(engine.placementFitsPhoto(placement), false, 'occupied floor cannot be used through an alpha gap');
    engine.existing = [{left:.3, top:.3, right:.4, bottom:.5}];
    assert.equal(engine.placementFitsPhoto(placement), true, 'transparent upper corner does not hide the existing piece');
    engine.existing = [{left:.6, top:.3, right:.7, bottom:.5}];
    assert.equal(engine.placementFitsPhoto(placement), false, 'visible furniture body cannot cover the existing piece');
});

function startingEngine(v2Enabled = true) {
    const engine = Object.create(globalThis.RoomPreviewEngine.prototype);
    Object.assign(engine, {
        frame: {classList: {contains: () => false}, dataset: {consented: 'true', placementStatus: 'completed', previewV2Url: '/preview-v2', previewV2Enabled: String(v2Enabled), verifyUrl: '/verify'}},
        openings: [],
        canvas: {}, context: {},
        room: {decode: async () => {}}, item: {decode: async () => {}},
        initialConfidence: 0.8, analysis: {gemini_used: true}, floorDetected: true,
        placementMode: 'floor', runId: 0, download: {disabled: false, onclick() {}},
        visibleImageBounds: () => ({x: 0, y: 0, width: 100, height: 100}),
        dimensionsFromCatalog: () => ({width: 100, depth: 50, height: 80}),
        estimateScale: () => 1,
        autoFit: () => ({mode: 'floor', x: 0.5, y: 0.85, width: 0.25, height: 0.2, scale: 1}),
        draw() {}, report() {}, setProgress() {}, setStatus() {}, drawRoomOnly() {},
        enhancedCalls: 0, verificationCalls: 0,
        async generateAiPreview(runId, retry) { this.enhancedCalls++; this.sentRetry = retry; return false; },
        async verifyLoop() { this.verificationCalls++; },
    });
    return engine;
}

test('initial automatic 2D rendering makes no generation or verification requests', async () => {
    for (const enabled of [false, true]) {
        const engine = startingEngine(enabled);
        await engine.start();

        assert.ok(engine.result);
        assert.equal(engine.download.disabled, false);
        assert.equal(engine.enhancedCalls, 0);
        assert.equal(engine.verificationCalls, 0);
        assert.equal(JSON.parse(engine.frame.dataset.finalPlacement).mode, 'floor');
    }
});

test('explicit enhancement retry does not trigger another verification chain after failure', async () => {
    const engine = startingEngine();
    engine.retryAiPreview = true;
    await engine.start();

    assert.equal(engine.enhancedCalls, 1);
    assert.equal(engine.sentRetry, true);
    assert.equal(engine.verificationCalls, 0);
    assert.equal(engine.download.disabled, false, 'the immediate 2D placement remains downloadable');
    await engine.start();
    assert.equal(engine.enhancedCalls, 1, 'an ordinary rerender must not repeat the explicit retry');
});

test('explicit retry uses visual verification only when enhanced generation is disabled', async () => {
    const engine = startingEngine(false);
    engine.retryAiPreview = true;
    await engine.start();

    assert.equal(engine.enhancedCalls, 0);
    assert.equal(engine.verificationCalls, 1);
});

test('a blocked placement clears the prior result and disables stale preview downloads', async () => {
    const engine = startingEngine();
    engine.result = {mode: 'floor', x: 0.2};
    engine.frame.dataset.finalPlacement = JSON.stringify(engine.result);
    engine.autoFit = () => null;
    let originalShown = false;
    engine.drawRoomOnly = () => { originalShown = true; };

    await engine.start();

    assert.equal(engine.result, null);
    assert.equal(engine.frame.dataset.finalPlacement, '');
    assert.equal(engine.download.disabled, true);
    assert.equal(engine.download.onclick, null);
    assert.equal(originalShown, true);
    assert.equal(engine.enhancedCalls + engine.verificationCalls, 0);
});

test('a door without a detected opening shows analysis progress before a final placement result', async () => {
    const engine = startingEngine();
    engine.placementMode = 'door';
    engine.autoFit = () => null;
    engine.status = {textContent: ''};
    engine.guidance = {textContent: ''};
    engine.setStatus = function (message) { this.status.textContent = message; };
    engine.frame.dataset.placementStatus = 'processing';

    await engine.start();
    assert.equal(engine.status.textContent, 'Analyzing doorway in your photo');
    assert.match(engine.guidance.textContent, /still being analyzed/);
    assert.equal(engine.download.disabled, true);

    engine.frame.dataset.placementStatus = 'fallback';
    await engine.start();
    assert.equal(engine.status.textContent, 'No confirmed doorway available');
    assert.match(engine.guidance.textContent, /door has not been placed/);
    assert.equal(engine.download.disabled, true);
    engine.analysis.gemini_used = false;
    await engine.start();
    assert.equal(engine.status.textContent, 'Doorway analysis unavailable');
    assert.match(engine.guidance.textContent, /does not mean the doorway frame is missing/);
    assert.equal(engine.download.disabled, true);
});

async function withEnhancementResponse(payload, work, decodeFails = false) {
    const originals = {fetch: globalThis.fetch, Image: globalThis.Image, createElement: document.createElement};
    globalThis.fetch = async () => ({ok: true, json: async () => payload});
    globalThis.Image = class {
        naturalWidth = 1200;
        naturalHeight = 800;
        async decode() { if (decodeFails) throw new Error('image unavailable'); }
    };
    document.createElement = () => ({getContext: () => ({drawImage() {}})});
    try { await work(); } finally { Object.assign(globalThis, {fetch: originals.fetch, Image: originals.Image}); document.createElement = originals.createElement; }
}

function enhancementEngine() {
    const engine = startingEngine();
    Object.assign(engine, {
        generateAiPreview: globalThis.RoomPreviewEngine.prototype.generateAiPreview,
        report: globalThis.RoomPreviewEngine.prototype.report,
        result: {mode: 'floor', x: 0.4, y: 0.8, width: 0.25, height: 0.2, scale: 1},
        canvas: {width: 1200, height: 800}, warnings: [], openings: [],
        guidance: {textContent: ''}, detections: {innerHTML: ''}, placementConfidence: 0.8,
        drawCount: 0,
        setStatus(text) { this.statusText = text; },
        canvasSize() { throw new Error('the usable local canvas must not be resized'); },
    });
    engine.context = {drawImage() { engine.drawCount++; }};
    engine.frame.dataset.productId = '1';
    engine.frame.dataset.finalPlacement = JSON.stringify(engine.result);
    return engine;
}

test('server fallback artifacts do not overwrite the browser 2D preview or its placement metadata', async () => {
    const engine = enhancementEngine();
    const original = engine.result;
    await withEnhancementResponse({status: 'fallback', preview_url: '/fallback.png', placement: {x_percent: 80}, message: 'Provider unavailable.'}, async () => {
        assert.equal(await engine.generateAiPreview(0, true), false);
    });

    assert.equal(engine.result, original);
    assert.equal(engine.drawCount, 0);
    assert.deepEqual(JSON.parse(engine.frame.dataset.finalPlacement), original);
    assert.match(engine.guidance.textContent, /Provider unavailable/);
});

test('accepted AI images replace stale coordinates with explicit generated-image metadata', async () => {
    const engine = enhancementEngine();
    const placementGuidance = {x_percent: 65, y_percent: 86, max_width_percent: 30};
    await withEnhancementResponse({status: 'completed', preview_url: '/accepted.png', placement: placementGuidance}, async () => {
        assert.equal(await engine.generateAiPreview(0, true), true);
    });

    assert.equal(engine.drawCount, 1);
    assert.equal(engine.result.mode, 'generated-image');
    assert.equal(engine.result.aiVisualCheckPassed, true);
    assert.equal(engine.result.physicalFitConfirmed, false);
    assert.equal(engine.result.x, undefined, 'generator instructions must not be presented as measured output coordinates');
    assert.equal(engine.result.width, undefined);
    assert.deepEqual(engine.result.placementGuidance, placementGuidance);
    assert.deepEqual(JSON.parse(engine.frame.dataset.finalPlacement), engine.result);
    assert.equal(engine.statusText, 'AI visual preview ready');
    assert.match(engine.guidance.textContent, /physical fit is not confirmed/);
    assert.doesNotMatch(engine.detections.innerHTML, /High confidence|Floor detected/);
});

test('failed AI artifact decoding preserves the local canvas and placement', async () => {
    const engine = enhancementEngine();
    const original = engine.result;
    await withEnhancementResponse({status: 'completed', preview_url: '/missing.png'}, async () => {
        assert.equal(await engine.generateAiPreview(0, true), false);
    }, true);

    assert.equal(engine.drawCount, 0);
    assert.equal(engine.result, original);
    assert.deepEqual(JSON.parse(engine.frame.dataset.finalPlacement), original);
    assert.match(engine.guidance.textContent, /could not be loaded/);
});
