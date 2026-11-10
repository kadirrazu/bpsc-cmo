import test from 'node:test';
import assert from 'node:assert/strict';
// The slide planner is independent of layout; no browser or npm test dependency is required.
globalThis.document ??= {querySelectorAll: () => []};
const {buildVivaSlides} = await import('../../resources/js/viva-display.js');
const board=(post,group,count)=>({post_code:post,board:group,registrations:Array.from({length:count},(_,i)=>String(i+1).padStart(8,'0'))});
test('every candidate appears once across chunks, including the final partial slide',()=>{
 const source=board('250019','01',113),slides=buildVivaSlides([source],24);
 assert.equal(slides.length,5);assert.deepEqual(slides.flatMap(s=>s.registrations),source.registrations);
 assert.equal(slides.at(-1).offset,96);assert.equal(slides.at(-1).registrations.length,17);
});
test('slides group posts and boards numerically without changing registrations or inputs',()=>{
 const source=[board('990099','13',1),board('250019','10',1),board('250019','02',1)];
 const slides=buildVivaSlides(source,24);
 assert.deepEqual(slides.map(s=>`${s.board.post_code}/${s.board.board}`),['250019/02','250019/10','990099/13']);
 assert.equal(source[0].post_code,'990099');assert.equal(slides[0].registrations[0],'00000001');
});
test('empty data and invalid screen capacity do not lose candidates or loop forever',()=>{
 assert.deepEqual(buildVivaSlides([],24),[]);assert.equal(buildVivaSlides([board('01','01',3)],0).length,3);
 assert.equal(buildVivaSlides([board('01','01',0)],24).length,1);
});
