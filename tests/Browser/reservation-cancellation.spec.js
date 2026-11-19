import {expect, test} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {dirname, join, resolve} from 'node:path';
import {tmpdir} from 'node:os';

function php(code) {
    const directory=dirname(process.env.FURNICRAFT_BROWSER_DB);
    return execFileSync('php',['-d','variables_order=EGPCS','-r',
        "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "+code],{
        cwd:process.cwd(),encoding:'utf8',windowsHide:true,env:{...process.env,APP_ENV:'testing',APP_CONFIG_CACHE:join(directory,'config.php'),
            DB_CONNECTION:'sqlite',DB_DATABASE:process.env.FURNICRAFT_BROWSER_DB,LARAVEL_STORAGE_PATH:join(directory,'storage'),
            PRIVATE_STORAGE_ROOT:join(directory,'private'),CACHE_STORE:'array',SESSION_DRIVER:'file',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',GEMINI_API_KEY:''},
    });
}

test('reservation cancellation uses a centered reason dialog with Yes and No',async ({page},info)=>{
    const suffix=info.project.name;
    const references=JSON.parse(php(`
        $user=App\\Models\\User::where('email','browser-customer@example.test')->firstOrFail(); $refs=[];
        foreach(['pending','confirmed'] as $status){
            $product=App\\Models\\FurnitureProduct::create(['name'=>'Cancellation fixture '.$status,'slug'=>'cancel-${suffix}-'.$status,
                'category'=>'Table','description'=>'Isolated browser fixture','wood_type'=>'Wood','varnish_finish'=>'Natural','style'=>'Modern','price'=>12000,'status'=>'active','image_path'=>'demo:table.svg']);
            $ref='FC-CANCEL-${suffix}-'.$status;
            App\\Models\\Reservation::create(['reference'=>$ref,'user_id'=>$user->id,'furniture_product_id'=>$product->id,
                'branch'=>config('furnicraft.branch'),'status'=>$status,'confirmed_at'=>$status==='confirmed' ? now() : null]);
            $refs[$status]=$ref;
        } echo json_encode($refs);
    `));
    const errors=[],nativeDialogs=[],requests=[],deletions=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.on('dialog',async dialog=>{nativeDialogs.push(dialog.message());await dialog.dismiss();});
    page.on('request',request=>{if(request.method()==='POST'&&new URL(request.url()).pathname.endsWith('/cancel'))requests.push(request);});
    page.on('request',request=>{if(request.method()==='POST'&&/^\/reservations\/\d+$/.test(new URL(request.url()).pathname))deletions.push(request);});
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',route=>route.fulfill({path:resolve('node_modules/bootstrap/dist/css/bootstrap.min.css'),contentType:'text/css'}));
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',route=>route.fulfill({path:resolve('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'),contentType:'text/javascript'}));
    await page.goto('/login');
    await page.getByLabel('Email address').fill('browser-customer@example.test');
    await page.getByLabel('Password',{exact:true}).fill('BrowserTest-12345');
    await page.getByRole('button',{name:'Sign in'}).click();
    await page.goto('/reservations');
    await expect(page).toHaveTitle(/Reservations/);
    const card=ref=>page.locator('.reservation-card').filter({hasText:ref});
    const pending=card(references.pending),confirmed=card(references.confirmed);
    const trigger=pending.getByRole('button',{name:'Cancel request'});
    const dialog=page.getByRole('dialog',{name:'Cancel this reservation?'});
    const reason=dialog.getByLabel('Reason (optional)');
    await expect(pending.locator('input[name="cancellation_reason"]')).toHaveAttribute('type','hidden');
    await trigger.click(); await expect(dialog).toBeVisible();
    await expect(reason).toBeFocused(); await expect(reason).toHaveAttribute('maxlength','1000');
    await expect(dialog.getByRole('button')).toHaveText(['No','Yes']);
    await expect(dialog).toContainText(references.pending);
    const bounds=await dialog.boundingBox(),viewport=page.viewportSize();
    expect(Math.abs(bounds.x+bounds.width/2-viewport.width/2)).toBeLessThan(2);
    expect(Math.abs(bounds.y+bounds.height/2-viewport.height/2)).toBeLessThan(2);
    await reason.fill('Do not submit this reason.');
    await dialog.getByRole('button',{name:'No',exact:true}).click();
    await expect(dialog).toBeHidden(); await expect(trigger).toBeFocused();
    expect(requests).toHaveLength(0);
    await expect(pending.locator('.status')).toHaveText('Pending');
    await confirmed.getByRole('button',{name:'Cancel request'}).click();
    await expect(dialog).toContainText(references.confirmed); await expect(reason).toHaveValue('');
    await page.keyboard.press('Escape'); await expect(dialog).toBeHidden();
    expect(requests).toHaveLength(0);
    await trigger.click();
    await reason.fill('My visit schedule changed.');
    for(let i=0;i<6;i++)await page.keyboard.press('Tab');
    expect(await dialog.evaluate(node=>node.contains(document.activeElement))).toBe(true);
    await reason.focus();
    await page.screenshot({path:join(tmpdir(),`furnicraft-cancellation-${suffix}.png`),fullPage:false});
    await dialog.getByRole('button',{name:'Yes',exact:true}).click();
    await expect(card(references.pending).locator('.status')).toHaveText('Cancelled');
    await expect(page.getByText('The reservation request was cancelled.',{exact:true})).toHaveCount(0);
    await expect(card(references.pending)).toContainText('My visit schedule changed.');
    await expect(card(references.pending).getByRole('button',{name:'Delete',exact:true})).toBeVisible();
    await expect(card(references.confirmed).locator('.status')).toHaveText('Request approved');
    await expect(card(references.confirmed).getByRole('button',{name:'Delete',exact:true})).toHaveCount(0);
    expect(requests).toHaveLength(1);
    const stored=JSON.parse(php(`echo App\\Models\\Reservation::whereIn('reference',[${JSON.stringify(references.pending)},${JSON.stringify(references.confirmed)}])->get(['reference','status','cancellation_reason'])->toJson();`));
    expect(stored.find(row=>row.reference===references.pending)).toMatchObject({status:'cancelled',cancellation_reason:'My visit schedule changed.'});
    expect(stored.find(row=>row.reference===references.confirmed)).toMatchObject({status:'confirmed',cancellation_reason:null});
    await card(references.confirmed).getByRole('button',{name:'Cancel request'}).click();
    await expect(reason).toHaveValue('');
    await dialog.getByRole('button',{name:'Yes',exact:true}).click();
    await expect(card(references.confirmed).locator('.status')).toHaveText('Cancelled');
    const deletionDialog=page.getByRole('dialog',{name:'Delete this cancelled reservation?'});
    const deleteButton=card(references.pending).getByRole('button',{name:'Delete',exact:true});
    await deleteButton.click(); await expect(deletionDialog).toBeVisible();
    await expect(deletionDialog).toContainText(references.pending);
    await expect(deletionDialog.getByRole('button')).toHaveText(['No','Yes']);
    await deletionDialog.getByRole('button',{name:'No',exact:true}).click();
    await expect(deletionDialog).toBeHidden(); await expect(deleteButton).toBeFocused(); expect(deletions).toHaveLength(0);
    await deleteButton.click(); await page.keyboard.press('Escape');
    await expect(deletionDialog).toBeHidden(); expect(deletions).toHaveLength(0);
    await deleteButton.click();
    await page.screenshot({path:join(tmpdir(),`furnicraft-delete-reservation-${suffix}.png`),fullPage:false});
    await deletionDialog.getByRole('button',{name:'Yes',exact:true}).click();
    await expect(card(references.pending)).toHaveCount(0);
    await expect(card(references.confirmed)).toHaveCount(1);
    await expect(page.getByText('The reservation request was cancelled.',{exact:true})).toHaveCount(0);
    expect(deletions).toHaveLength(1);
    const retained=JSON.parse(php(`$reservation=App\\Models\\Reservation::where('reference',${JSON.stringify(references.pending)})->firstOrFail(); echo json_encode(['status'=>$reservation->status,'markers'=>$reservation->transactions()->where('action',App\\Models\\Reservation::CUSTOMER_DELETED_ACTION)->count()]);`));
    expect(retained).toEqual({status:'cancelled',markers:1});
    await page.reload(); await expect(card(references.pending)).toHaveCount(0);
    expect(requests).toHaveLength(2); expect(nativeDialogs).toEqual([]); expect(errors).toEqual([]);
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
    expect(overflow).toBe(false);
});
