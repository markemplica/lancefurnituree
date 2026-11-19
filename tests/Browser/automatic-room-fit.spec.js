import { expect, test } from '@playwright/test';
import { join, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { readFileSync } from 'node:fs';

const evidenceDirectory = process.env.FURNICRAFT_PREVIEW_EVIDENCE || tmpdir();
const doorwayCases = JSON.parse(readFileSync(resolve('tests/Fixtures/doorway-fit-quads.json'), 'utf8'));
const pageErrors = new WeakMap();

// Synthetic, non-sensitive photo and item pixels run through the actual page renderer.
async function fixture(page, options = {}) {
    return page.locator('[data-room-preview]').evaluate(async (frame, options) => {
        const scene = document.createElement('canvas'); scene.width = options.portrait ? 600 : 800; scene.height = options.portrait ? 800 : 600;
        const sc = scene.getContext('2d'); sc.fillStyle = '#dbcebe'; sc.fillRect(0,0,scene.width,scene.height);
        sc.fillStyle = '#936f4e'; sc.fillRect(0,scene.height*.6,scene.width,scene.height*.4);
        const item = document.createElement('canvas'); item.width = 120; item.height = 240;
        const ic = item.getContext('2d');
        if (!options.transparent) { ic.fillStyle = '#70402a'; ic.fillRect(0,0,120,240); ic.fillStyle = '#ffffff'; ic.fillRect(30,30,60,70); }
        frame.querySelector('[data-preview-room]').src = scene.toDataURL();
        const base = frame.querySelector('.comparison-room-base'); if (base) base.src = scene.toDataURL();
        frame.querySelector('[data-preview-product]').src = options.assetUrl || item.toDataURL();
        const analysis = {gemini_used:true, vision_geometry:{image_quality:{usable:true,issues:[]},confidence:.9,
            floor_polygon:[[0,.6],[1,.6],[1,1],[0,1]], blocked_zones:[], openings:options.openings || []}};
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        Object.assign(frame.dataset, {productType:options.door?'Door':'Chair', productCategory:options.door?'Door':'Chair',
            productWidth:'90',productHeight:'210',productDepth:'4',placementSurface:options.door?'opening':'floor',
            placementRenderable:options.door?'false':'true',placementSource:'candidate-zone',placementStatus:'completed',
            initialX:options.left?'28':'65',initialY:'90',initialWidth:'20',initialQuad:'null',placementRotation:'0',placementPerspective:'0'});
        const engine = new window.RoomPreviewEngine(frame); await engine.start();
        return {result:engine.result,pixels:engine.visibleProductPixelCount || 0,status:engine.status.textContent};
    }, options);
}

async function pixelsOnPage(page) {
    return page.locator('[data-room-preview]').evaluate(frame => {
        const e = frame.roomPreviewEngine, w=e.canvas.width, h=e.canvas.height;
        const original=document.createElement('canvas'); original.width=w; original.height=h;
        original.getContext('2d').drawImage(e.room,0,0,w,h);
        const a=original.getContext('2d').getImageData(0,0,w,h).data, b=e.context.getImageData(0,0,w,h).data;
        const bounds=frame.getBoundingClientRect(), canvas=e.canvas.getBoundingClientRect();
        const scale=Math.min(canvas.width/w,canvas.height/h), left=canvas.left+(canvas.width-w*scale)/2;
        const split=parseFloat(getComputedStyle(frame).getPropertyValue('--split')) || 50;
        const reveal=frame.classList.contains('room-comparison')?bounds.left+bounds.width*split/100:bounds.left;
        let changed=0,visible=0,white=0;
        for(let y=0;y<h*.78;y++)for(let x=0;x<w;x++) {
            const i=(y*w+x)*4;
            if(Math.abs(a[i]-b[i])+Math.abs(a[i+1]-b[i+1])+Math.abs(a[i+2]-b[i+2])>45) {
                changed++; if(left+x*scale>=reveal)visible++;
                if(b[i]===255 && b[i+1]===255 && b[i+2]===255)white++;
            }
        }
        return {changed,visible,white,split};
    });
}

for (const surface of ['/room/1/visualize?product=1', '/recommendations/1']) {
    test(`left portrait placement is visible with white product details at ${surface}`, async ({page}, info) => {
        await page.goto(surface);
        const result = await fixture(page, {portrait:true,left:true});
        expect(result.result).not.toBeNull(); expect(result.pixels).toBeGreaterThan(200);
        const pixels=await pixelsOnPage(page);
        expect(pixels.visible,JSON.stringify(pixels)).toBeGreaterThan(200);
        expect(pixels.white).toBeGreaterThan(100);
        if(surface.startsWith('/recommendations')) {
            expect(pixels.visible / pixels.changed).toBeGreaterThan(.95);
            const slider=page.getByRole('slider'); await slider.focus();
            await page.keyboard.press('Home'); await expect(slider).toHaveAttribute('aria-valuenow','0');
            await page.keyboard.press('ArrowRight'); await expect(slider).toHaveAttribute('aria-valuenow','4');
            await page.keyboard.press('End'); await expect(slider).toHaveAttribute('aria-valuenow','100');
            await page.keyboard.press('Home');
            await slider.scrollIntoViewIfNeeded();
            const box=await slider.boundingBox();
            if(info.project.name==='mobile-edge') {
                const cdp=await page.context().newCDPSession(page);
                await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:box.x+box.width*.25,y:box.y+box.height*.5}]});
                await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:box.x+box.width*.75,y:box.y+box.height*.5}]});
                await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
                await expect(slider).toHaveAttribute('aria-valuenow',/7\d/);
                await page.keyboard.press('Home');
            }
        }
        await page.locator('[data-room-preview]').screenshot({path:join(evidenceDirectory,`preview-visible-${info.project.name}-${surface.startsWith('/room')?'standalone':'recommendations'}.png`)});
    });

    test(`bad assets preserve the original room and disable download at ${surface}`, async ({page}) => {
        await page.route('**/__missing-item.png',route=>route.fulfill({status:404,body:''}));
        await page.route('**/__corrupt-item.png',route=>route.fulfill({contentType:'image/png',body:'broken image'}));
        await page.goto(surface);
        for(const options of [{transparent:true},{assetUrl:'/__missing-item.png'},{assetUrl:'/__corrupt-item.png'},{assetUrl:'/images/furniture/default.svg'}]) {
            const result=await fixture(page,options);
            expect(result.result).toBeNull();
            await expect(page.locator('[data-preview-download]')).toBeDisabled();
            const proof=await pixelsOnPage(page); expect(proof.changed).toBe(0);
            await expect(page.locator('.placement-status [data-placement-status]')).toContainText(options.transparent?'no usable visible pixels':options.assetUrl.includes('default.svg')?'needs a product photo':'could not be loaded');
        }
        const valid=await fixture(page); expect(valid.pixels).toBeGreaterThan(200);
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
    });

    test(`doorway perspective and missing-corner feedback are accurate at ${surface}`, async ({page},info) => {
        await page.goto(surface);
        for(const sample of doorwayCases) {
            const result=await fixture(page,{door:true,openings:[{type:'door',bbox:[.12,.12,.4,.91],door_fit_quad_percent:sample.quad}]});
            expect(Boolean(result.result),sample.name).toBe(sample.valid);
            if(sample.valid) {
                expect(result.result.mode).toBe('door'); expect(result.pixels).toBeGreaterThan(200);
                expect((await pixelsOnPage(page)).visible).toBeGreaterThan(200);
            } else await expect(page.locator('[data-preview-download]')).toBeDisabled();
        }
        for(const opening of [{type:'door',bbox:[.12,.12,.4,.91]},
            {type:'window',bbox:[.12,.12,.4,.91],door_fit_quad_percent:doorwayCases[0].quad},
            {type:'door',bbox:[.12,.12,.4,.91],frame_complete:false,door_fit_quad_percent:doorwayCases[0].quad}]) {
            const result=await fixture(page,{door:true,openings:[opening]}); expect(result.result).toBeNull();
            await expect(page.locator('[data-preview-download]')).toBeDisabled();
            if(opening.type==='door') await expect(page.locator('[data-placement-guidance]')).toContainText('fit corners');
        }
        const catalogDoor=await fixture(page,{door:true,assetUrl:'/images/catalog/catalog-7-panel-door-cutout.png',openings:[{type:'door',door_fit_quad_percent:doorwayCases[0].quad}]});
        expect(catalogDoor.pixels).toBeGreaterThan(200);
        await page.locator('[data-room-preview]').screenshot({path:join(evidenceDirectory,`preview-door-${info.project.name}-${surface.startsWith('/room')?'standalone':'recommendations'}.png`)});
    });

    test(`pending analysis updates the current selected product without navigation at ${surface}`, async ({page}) => {
        const pathname=surface.split('?')[0];
        await page.route(url=>url.pathname===pathname,async route=>{
            const response=await route.fetch();
            const html=(await response.text()).replaceAll('data-room-analysis-current="fallback"','data-room-analysis-current="processing"').replaceAll('data-placement-status="fallback"','data-placement-status="processing"');
            await route.fulfill({response,body:html});
        });
        const quad=doorwayCases[0].quad;
        const analysis={summary:'Updated local fixture geometry',gemini_used:true,vision_geometry:{image_quality:{usable:true,issues:[]},confidence:.9,
            floor_polygon:[],openings:[{type:'door',door_fit_quad_percent:quad}],blocked_zones:[],existing_furniture:[]}};
        let queries=[];
        await page.route(url=>url.pathname.endsWith('/analysis-status'),async route=>{
            queries.push(new URL(route.request().url()).searchParams.get('product'));
            await route.fulfill({json:{status:'completed',ready:true,analysis,product_id:2,placement:{renderable:true,surface:'opening',
                x_percent:26,y_percent:91,max_width_percent:28,quad_percent:quad,source:'scene-opening',confidence:'medium',score:80,reason:'Detected room-facing frame',flip:1,rotation_deg:0,perspective_deg:0}}});
        });
        await page.goto(surface);
        await expect.poll(()=>page.locator('[data-room-preview]').evaluate(frame=>Boolean(frame.roomPreviewEngine?.result))).toBe(true);
        await page.locator('[data-room-preview]').evaluate(async frame=>{
            const product=document.createElement('canvas');product.width=120;product.height=240;
            product.getContext('2d').fillStyle='#70402a';product.getContext('2d').fillRect(0,0,120,240);
            const button=document.createElement('button');Object.assign(button.dataset,{productId:'2',productName:'Current fixture door',productType:'Door',productCategory:'Door',
                productImage:product.toDataURL(),productWidth:'90',productDepth:'4',productHeight:'210',placementRenderable:'false',placementSurface:'opening'});
            await frame.roomPreviewEngine.selectProduct(button);
        });
        await expect.poll(()=>page.locator('[data-room-preview]').evaluate(frame=>frame.roomPreviewEngine?.analysis?.summary),{timeout:10000}).toBe(analysis.summary);
        await expect.poll(()=>page.locator('[data-room-preview]').evaluate(frame=>frame.roomPreviewEngine?.result?.mode)).toBe('door');
        await expect(page.locator('[data-room-preview]')).toHaveAttribute('data-product-id','2');
        await expect(page).toHaveURL('http://127.0.0.1:8001'+surface);
        expect(queries.at(-1)).toBe('2');
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
    });

    test(`late enhanced artifact cannot overwrite a newly selected item at ${surface}`, async ({page}) => {
        await page.goto(surface); await fixture(page);
        let delayedArtifact;
        const artifactRequested=new Promise(resolve=>{delayedArtifact=resolve;});
        let heldRoute;
        await page.route('**/__old-artifact.png',route=>{heldRoute=route;delayedArtifact();});
        await page.route('**/__enhance',route=>route.fulfill({json:{status:'completed',preview_url:'/__old-artifact.png',placement:{x_percent:50}}}));
        await page.locator('[data-room-preview]').evaluate(frame=>{
            frame.dataset.previewV2Url='/__enhance'; frame.dataset.productId='1';
            window.oldEnhancement=frame.roomPreviewEngine.generateAiPreview(frame.roomPreviewEngine.runId,true);
        });
        await artifactRequested;
        await page.locator('[data-room-preview]').evaluate(async frame=>{
            const button=document.createElement('button'); Object.assign(button.dataset,{
                productId:'2',productName:'Current fixture item',productType:'Chair',productCategory:'Chair',
                productImage:frame.querySelector('[data-preview-product]').src,productWidth:'90',productDepth:'4',productHeight:'210',
                placementRenderable:'true',placementX:'70',placementY:'90',placementWidth:'20',placementSurface:'floor',placementSource:'candidate-zone'});
            await frame.roomPreviewEngine.selectProduct(button);
            window.currentCanvas=frame.roomPreviewEngine.canvas.toDataURL();
        });
        const bytes=await page.locator('[data-room-preview]').evaluate(frame=>frame.roomPreviewEngine.canvas.toDataURL().split(',')[1]);
        await heldRoute.fulfill({body:Buffer.from(bytes,'base64'),contentType:'image/png'});
        expect(await page.evaluate(()=>window.oldEnhancement)).toBe(false);
        const result=await page.locator('[data-room-preview]').evaluate(frame=>({id:frame.dataset.productId,mode:frame.roomPreviewEngine.result.mode,unchanged:window.currentCanvas===frame.roomPreviewEngine.canvas.toDataURL()}));
        expect(result).toEqual({id:'2',mode:'floor',unchanged:true});
    });
}

test.beforeEach(async ({ page }) => {
    pageErrors.set(page, []);
    page.on('pageerror', error => pageErrors.get(page).push(error.message));
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css', (route) => route.fulfill({
        path: resolve('node_modules/bootstrap/dist/css/bootstrap.min.css'), contentType: 'text/css',
    }));
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js', (route) => route.fulfill({
        path: resolve('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'), contentType: 'text/javascript',
    }));
    await page.goto('/login');
    await page.getByLabel('Email address').fill('browser-customer@example.test');
    await page.getByLabel('Password', { exact: true }).fill('BrowserTest-12345');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/');
});

test.afterEach(async ({page}) => { expect(pageErrors.get(page)).toEqual([]); });

for (const url of ['/room/1/visualize?product=1', '/recommendations/1']) {
    test(`automatic 2D preview is ready without provider requests at ${url}`, async ({ page }, testInfo) => {
        const providerRequests = [];
        const errors = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/preview-(?:v2|verify)(?:\/|$)/.test(new URL(request.url()).pathname)) providerRequests.push(request.url());
        });
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(url);
        const canvas = page.locator('[data-room-preview-canvas]');
        await expect(canvas).toBeVisible();
        await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
        const savedAnalysis = await page.locator('[data-room-preview]').evaluate((frame) => ({
            stored: JSON.parse(frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent),
            loaded: frame.roomPreviewEngine.analysis,
        }));
        expect(savedAnalysis.loaded).toEqual(savedAnalysis.stored);
        // Exercise a consented rerender without contacting the provider or changing stored consent.
        await page.locator('[data-room-preview]').evaluate(async (frame) => {
            frame.dataset.consented = 'true';
            frame.dataset.previewV2Enabled = 'true';
            await frame.roomPreviewEngine.start();
        });
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
        expect(providerRequests).toEqual([]);
        expect(errors).toEqual([]);
        const layout = await page.evaluate(() => ({width: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth}));
        expect(layout.width).toBeLessThanOrEqual(layout.viewport + 1);
        const measurePixels = (frame) => {
            const engine = frame.roomPreviewEngine;
            const { width, height } = engine.canvas;
            const baseline = document.createElement('canvas'); baseline.width = width; baseline.height = height;
            baseline.getContext('2d').drawImage(engine.room, 0, 0, width, height);
            const before = baseline.getContext('2d').getImageData(0, 0, width, height).data;
            const after = engine.context.getImageData(0, 0, width, height).data;
            const p = engine.result;
            const mask = document.createElement('canvas'); mask.width = width; mask.height = height;
            const ctx = mask.getContext('2d');
            const crop = engine.productImageBounds;
            ctx.translate(p.x * width, p.y * height - p.height * height / 2);
            ctx.rotate((Number(p.rotationDeg) || 0) * Math.PI / 180);
            ctx.transform(1, 0, Math.tan((Number(p.perspectiveDeg) || 0) * Math.PI / 180) * 0.35, 1, 0, 0);
            ctx.scale(p.flip === true ? -1 : 1, 1);
            ctx.drawImage(engine.productRenderImage || engine.item, crop.x, crop.y, crop.width, crop.height, -p.width * width / 2, -p.height * height / 2, p.width * width, p.height * height);
            const source = ctx.getImageData(0, 0, width, height).data;
            const frameBounds = frame.getBoundingClientRect();
            const canvasBounds = engine.canvas.getBoundingClientRect();
            const displayScale = Math.min(canvasBounds.width / width, canvasBounds.height / height);
            const imageLeft = canvasBounds.left + (canvasBounds.width - width * displayScale) / 2;
            const imageTop = canvasBounds.top + (canvasBounds.height - height * displayScale) / 2;
            const split = parseFloat(getComputedStyle(frame).getPropertyValue('--split')) || 50;
            const visibleLeft = frame.classList.contains('room-comparison') ? frameBounds.left + frameBounds.width * split / 100 : frameBounds.left;
            let productPixels = 0, visibleProductPixels = 0, maskPixels = 0, allChanged = 0;
            for (let y = 0; y < Math.floor((p.y - p.height * 0.1) * height); y++) {
                for (let x = 0; x < width; x++) {
                    const i = (y * width + x) * 4;
                    if (Math.abs(after[i] - before[i]) + Math.abs(after[i + 1] - before[i + 1]) + Math.abs(after[i + 2] - before[i + 2]) > 45) allChanged++;
                    if (source[i + 3] < 200) continue;
                    maskPixels++;
                    const delta = Math.abs(after[i] - before[i]) + Math.abs(after[i + 1] - before[i + 1]) + Math.abs(after[i + 2] - before[i + 2]);
                    if (delta > 45) {
                        productPixels++;
                        const screenX = imageLeft + x * displayScale;
                        const screenY = imageTop + y * displayScale;
                        if (screenX >= visibleLeft && screenX <= frameBounds.right && screenY >= frameBounds.top && screenY <= frameBounds.bottom) visibleProductPixels++;
                    }
                }
            }
            return {productPixels, visibleProductPixels, visibleRatio: visibleProductPixels / Math.max(1, productPixels), maskPixels, allChanged, crop, item: [engine.item.naturalWidth, engine.item.naturalHeight], frame: [frameBounds.width, frameBounds.height], canvas: [canvasBounds.width, canvasBounds.height], split, placement: p};
        };
        const pixelProof = await page.locator('[data-room-preview]').evaluate(measurePixels);
        const suffix = url.startsWith('/recommendations') ? 'comparison' : 'dedicated';
        await page.screenshot({ path: join(tmpdir(), `furnicraft-auto-fit-${testInfo.project.name}-${suffix}.png`), fullPage: true });
        expect(pixelProof.productPixels, JSON.stringify(pixelProof)).toBeGreaterThan(200);
        expect(pixelProof.visibleProductPixels, JSON.stringify(pixelProof)).toBeGreaterThan(200);
        expect(pixelProof.visibleRatio, JSON.stringify(pixelProof)).toBeGreaterThan(suffix === 'dedicated' ? 0.9 : 0.2);
        const downloaded = page.waitForEvent('download');
        await page.locator('[data-preview-download]').click();
        const download = await downloaded;
        expect(download.suggestedFilename()).toBe('furnicraft-room-preview.png');
        expect(readFileSync(await download.path()).length).toBeGreaterThan(100);
        expect(providerRequests).toEqual([]);
        // A portrait phone photo must also fit completely inside the frame.
        await page.locator('[data-room-preview]').evaluate(async (frame) => {
            const scene = document.createElement('canvas'); scene.width = 600; scene.height = 800;
            const ctx = scene.getContext('2d');
            ctx.fillStyle = '#dbcebe'; ctx.fillRect(0, 0, 600, 800);
            ctx.fillStyle = '#936f4e'; ctx.fillRect(0, 480, 600, 320);
            const uri = scene.toDataURL('image/png');
            const engine = frame.roomPreviewEngine;
            engine.room.src = uri;
            const before = frame.querySelector('.comparison-room-base');
            if (before) before.src = uri;
            await engine.room.decode();
            await engine.start();
        });
        const portraitProof = await page.locator('[data-room-preview]').evaluate(measurePixels);
        expect(portraitProof.productPixels, JSON.stringify(portraitProof)).toBeGreaterThan(200);
        expect(portraitProof.visibleRatio, JSON.stringify(portraitProof)).toBeGreaterThan(suffix === 'dedicated' ? 0.9 : 0.2);
        expect(providerRequests).toEqual([]);
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
        await page.screenshot({ path: join(tmpdir(), `furnicraft-auto-fit-${testInfo.project.name}-${suffix}-portrait.png`), fullPage: true });
        await page.reload();
        await expect.poll(() => page.locator('[data-room-preview]').evaluate(frame => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
        expect(providerRequests).toEqual([]);
    });
}

test('a fully blocked floor shows the original room and disables preview export', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
    await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const engine = frame.roomPreviewEngine;
        engine.floorDetected = true;
        engine.blocked = [[[0, 0], [1, 0], [1, 1], [0, 1]]];
        await engine.start();
    });

    await expect(page.locator('strong[data-placement-status]')).toHaveText('No validated placement available');
    await expect(page.locator('[data-placement-guidance]')).toContainText('No clear area passed the placement checks.');
    await expect(page.locator('[data-preview-download]')).toBeDisabled();
    expect(await page.locator('[data-room-preview]').evaluate((frame) => ({result: frame.roomPreviewEngine.result, finalPlacement: frame.dataset.finalPlacement}))).toEqual({result: null, finalPlacement: ''});
});

test('the sala-set fallback stays modest in size without AI floor geometry', async ({ page }, testInfo) => {
    await page.goto('/room/1/visualize?product=1');
    await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
    const placement = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const engine = frame.roomPreviewEngine;
        engine.item.src = '/images/catalog/drive-02.png';
        Object.assign(frame.dataset, {
            initialX: '64', initialY: '88', initialWidth: '68',
            productWidth: '220', productDepth: '88', productHeight: '92',
            productType: 'Sala Set', productCategory: 'Sala Set',
        });
        engine.itemType = 'sala set';
        await engine.start();
        return engine.result;
    });
    expect(placement.width).toBeLessThanOrEqual(0.5);
    expect(placement.height).toBeLessThanOrEqual(0.450001);
    expect(placement.y).toBeGreaterThanOrEqual(0.8);
    await expect(page.locator('[data-preview-download]')).toBeEnabled();
    await page.screenshot({path: join(tmpdir(), `furnicraft-local-sala-${testInfo.project.name}.png`), fullPage: true});
});

test('saved floor geometry and low placement confidence reach the renderer', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate((frame) => {
        const analysis = {
            gemini_used: true,
            vision_geometry: {
                confidence: 0.95,
                floor_polygon: [[0, 0.6], [1, 0.6], [1, 1], [0, 1]],
                blocked_zones: [{polygon: [[0, 0.6], [0.2, 0.6], [0.2, 1], [0, 1]]}],
            },
        };
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        frame.dataset.confidence = 'low';
        const engine = new window.RoomPreviewEngine(frame);
        return {floor: engine.floor, detected: engine.floorDetected, blocked: engine.blocked.length, confidence: engine.initialConfidence};
    });
    expect(result).toEqual({floor: [[0, 0.6], [1, 0.6], [1, 1], [0, 1]], detected: true, blocked: 1, confidence: 0.42});
});

test('AI sofa zone keeps its size in front of the wall and window', async ({ page }, testInfo) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const analysis = {gemini_used:true, vision_geometry:{confidence:.9,
            floor_polygon:[[0,.61],[.28,.66],[1,.66],[1,1],[0,1]],
            openings:[{type:'window', bbox:[.59,.23,.98,.55]}],
            blocked_zones:[{reason:'existing_furniture', polygon:[[.58,.55],[1,.55],[1,.66],[.58,.66]]}],
        }};
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        Object.assign(frame.dataset, {initialX:'62', initialY:'80', initialWidth:'42',
            productWidth:'220', productDepth:'88', productHeight:'92',
            productType:'Sala Set', productCategory:'Sala Set', placementSource:'candidate-zone',
            placementRotation:'-2', placementPerspective:'6'});
        frame.querySelector('[data-preview-product]').src = '/images/catalog/drive-02.png';
        const engine = new window.RoomPreviewEngine(frame);
        frame.roomPreviewEngine = engine;
        await engine.start();
        return {placement:engine.result, visibleCells:engine.productCollisionCells.length};
    });
    expect(result.placement.x).toBe(.62);
    expect(result.placement.y).toBe(.8);
    expect(result.placement.width).toBe(.42);
    expect(result.placement.depth).toBe(.126);
    expect(result.visibleCells).toBeGreaterThan(0);
    await expect(page.locator('[data-preview-download]')).toBeEnabled();
    await page.locator('[data-room-preview]').screenshot({path:join(tmpdir(), `furnicraft-sofa-wall-${testInfo.project.name}.png`)});
});

test('transparent product corners do not hide a doorway but visible furniture does', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const analysis = {vision_geometry:{floor_polygon:[[0,.6],[1,.6],[1,1],[0,1]],
            openings:[{type:'door', bbox:[.2,.25,.35,.45]}]}};
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        Object.assign(frame.dataset, {initialX:'50', initialY:'90', initialWidth:'60', placementRotation:'0', placementPerspective:'0'});
        const cutout = document.createElement('canvas'); cutout.width = 600; cutout.height = 500;
        const ctx = cutout.getContext('2d'); ctx.fillStyle = '#70402a';
        ctx.fillRect(300,0,300,500); ctx.fillRect(0,450,600,50);
        frame.querySelector('[data-preview-product]').src = cutout.toDataURL();
        const engine = new window.RoomPreviewEngine(frame);
        await engine.start();
        const placement = engine.result;
        const clear = placement && engine.placementFitsPhoto(placement);
        engine.bodyBlocked = [[[.6,.25],[.7,.25],[.7,.45],[.6,.45]]];
        return {width:placement?.width, clear, blocked:placement && !engine.placementFitsPhoto(placement)};
    });
    expect(result.width).toBeGreaterThan(0);
    expect(result.width).toBeLessThanOrEqual(.6);
    expect(result.clear).toBe(true);
    expect(result.blocked).toBe(true);
});
