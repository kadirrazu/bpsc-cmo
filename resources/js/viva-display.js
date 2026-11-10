export const memberDetails={member_number:'Member Number',floor:'Floor',room_number:'Room Number',lift_button_number:'Lift Button Number',name_of_po:'Name of PO'};
export function buildVivaSlides(boards,capacity=24){
 const slides=[];capacity=Math.max(1,Math.floor(capacity)||1);
 [...boards].sort((a,b)=>String(a.post_code).localeCompare(String(b.post_code),'en',{numeric:true})||String(a.board).localeCompare(String(b.board),'en',{numeric:true})).forEach(board=>{
  const registrations=board.registrations||[];
  for(let offset=0;offset<Math.max(1,registrations.length);offset+=capacity)slides.push({board,offset,registrations:registrations.slice(offset,offset+capacity),key:`${board.post_code}|${board.board}|${offset}`});
 });return slides;
}
export function renderVivaBoards(root,boards){
 root.vivaSlides?.destroy();
 const doc=root.ownerDocument,win=doc.defaultView,list=root.querySelector('[data-display-boards]'),position=root.querySelector('[data-display-position]');
 const el=(tag,css,text)=>{const n=doc.createElement(tag);n.className=css;if(text!==undefined)n.textContent=String(text);return n;};
 let slides=[],index=0,rotation,paused=false,resize,nextAt=Date.now()+15000;
 const state=()=>({key:slides[index]?.key,paused,nextAt});
 function draw(){
  list.replaceChildren();
  if(!slides.length){list.append(el('div','viva-display-empty','No boards have been assigned for this date yet. This screen updates automatically.'));if(position)position.textContent='Waiting for assignments';return;}
  const slide=slides[index],board=slide.board,card=el('article','viva-projector-board'),banner=el('div','viva-projector-banner');
  banner.append(el('span','',`Post Code ${board.post_code}`),el('strong','',`Board ${board.board}`));
  const body=el('div','viva-projector-body'),meta=el('div','viva-projector-meta');
  const postInfo=el('section','viva-post-info'),memberInfo=el('section','viva-member-info');
  postInfo.setAttribute('aria-label','Post information');memberInfo.setAttribute('aria-label','Member information');
  postInfo.append(el('span','viva-info-label','Post Name'),el('h2','',board.post_name));
  const postDetails=el('dl','viva-post-details');
  for(const key of ['unit','ministry'])if(![null,undefined,''].includes(board[key])){const row=el('div',`viva-post-detail-${key}`);row.append(el('dt','',key==='unit'?'Unit':'Ministry'),el('dd','',board[key]));postDetails.append(row);}
  if(postDetails.childElementCount)postInfo.prepend(postDetails);
  memberInfo.append(el('span','viva-info-label','Member Name'),el('h3','',board.member?.name||''));
  const details=el('dl','viva-member-details');
  Object.entries(memberDetails).forEach(([key,label])=>{if([null,undefined,''].includes(board.member?.[key]))return;const row=el('div',`viva-member-detail viva-member-detail-${key}`);row.append(el('dt','',label),el('dd','',board.member[key]));details.append(row);});
  if(details.childElementCount)memberInfo.append(details);
  meta.append(postInfo,memberInfo);
  const panel=el('section','viva-projector-candidates'),title=el('h4','',`Registration Numbers · ${slide.offset+1}–${slide.offset+slide.registrations.length} of ${board.candidate_count}`),regs=el('div','viva-projector-registrations');
  slide.registrations.forEach(reg=>regs.append(el('span','',reg)));panel.append(title,regs);body.append(meta,panel);card.append(banner,body);list.append(card);
  if(position)position.textContent=`Slide ${index+1} of ${slides.length} · ${paused?'Paused':'Changes every 15 seconds'}`;
  // Reduce type only when long optional metadata requires it; the registrations retain their own size.
  for(let size=22;size>=10;size--){meta.style.fontSize=`${size}px`;if(meta.scrollHeight<=meta.clientHeight)break;}
  const width=regs.clientWidth||600,height=regs.clientHeight||300,maxLength=Math.max(8,...slide.registrations.map(r=>String(r).length));
  const cols=Math.max(1,Math.floor(width/Math.max(155,maxLength*13))),rows=Math.max(1,Math.ceil(slide.registrations.length/cols));
  regs.style.gridTemplateColumns=`repeat(${cols},minmax(0,1fr))`;regs.style.gridTemplateRows=`repeat(${rows},minmax(0,1fr))`;
  regs.style.fontSize=`${Math.max(8,Math.min(30,(width/cols-18)/(maxLength*.63),(height/rows-12)/1.3))}px`;
 }
 function schedule(){clearTimeout(rotation);if(!paused&&slides.length>1)rotation=setTimeout(()=>{index=(index+1)%slides.length;nextAt=Date.now()+15000;draw();schedule();},Math.max(0,nextAt-Date.now()));}
 function rebuild(previous=state()){
  const width=list.clientWidth||1000,height=list.clientHeight||500,maxLength=Math.max(8,...boards.flatMap(b=>(b.registrations||[]).map(r=>String(r).length)));
  const regWidth=width>700?width*.66-32:width-32,regHeight=height-(width>700?105:235);
  const cols=Math.max(1,Math.floor(regWidth/Math.max(155,maxLength*13))),rows=Math.max(1,Math.floor(regHeight/52));
  slides=buildVivaSlides(boards,cols*rows);index=Math.max(0,slides.findIndex(s=>s.key===previous.key));paused=previous.paused||false;nextAt=previous.nextAt||Date.now()+15000;draw();schedule();
 }
 const events=[];const bind=(selector,fn)=>{const node=root.querySelector(selector);if(node){node.addEventListener('click',fn);events.push([node,fn]);}};
 bind('[data-display-next]',()=>{if(slides.length){index=(index+1)%slides.length;nextAt=Date.now()+15000;draw();schedule();}});
 bind('[data-display-prev]',()=>{if(slides.length){index=(index+slides.length-1)%slides.length;nextAt=Date.now()+15000;draw();schedule();}});
 bind('[data-display-pause]',event=>{paused=!paused;nextAt=Date.now()+15000;event.currentTarget.textContent=paused?'Resume':'Pause';draw();schedule();});
 const onResize=()=>{clearTimeout(resize);resize=setTimeout(()=>rebuild(),150);};win?.addEventListener('resize',onResize);
 const previous=root.vivaSlidePosition||{};rebuild(previous);
 const pauseButton=root.querySelector('[data-display-pause]');if(pauseButton)pauseButton.textContent=paused?'Resume':'Pause';
 root.vivaSlides={state,destroy(){root.vivaSlidePosition=state();clearTimeout(rotation);clearTimeout(resize);events.forEach(([n,f])=>n.removeEventListener('click',f));win?.removeEventListener('resize',onResize);}};
 return root.vivaSlides;
}
export function enhanceVivaDisplay(root,autoStart=true){
 const doc=root.ownerDocument,status=root.querySelector('[data-display-status]');let healthyStatus=status.textContent,etag='',timer,controller,stopped=false,running=false;
 const destroy=()=>{stopped=true;clearTimeout(timer);controller?.abort();root.vivaSlides?.destroy();};
 const initial=root.querySelector('[data-display-initial]');if(initial){renderVivaBoards(root,JSON.parse(initial.textContent));initial.remove();}
 const full=root.querySelector('[data-display-fullscreen]');full?.addEventListener('click',async()=>{try{if(doc.fullscreenElement)await doc.exitFullscreen();else await doc.documentElement.requestFullscreen();}catch{status.textContent='Use your browser’s full-screen option (F11).';}});
 async function refresh(){
  if(stopped||running)return;clearTimeout(timer);running=true;controller=new AbortController;const timeout=setTimeout(()=>controller.abort(),10000);
  try{
   if(root.dataset.followToday==='true'){const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Dhaka',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date);if(today!==root.dataset.date){root.dataset.date=today;etag='';const input=doc.querySelector('#screen-date');if(input)input.value=today;root.vivaSlides?.destroy();root.vivaSlidePosition={};root.querySelector('[data-display-boards]').replaceChildren();}}
   const url=new URL(root.dataset.url,doc.baseURI);url.searchParams.set('vdate',root.dataset.date);
   const response=await fetch(url,{headers:{Accept:'application/json',...(etag?{'If-None-Match':etag}:{})},credentials:'same-origin',cache:'no-store',signal:controller.signal});
   if([401,419].includes(response.status)){destroy();root.querySelector('[data-display-boards]').replaceChildren();status.textContent='Display session expired. Sign in again.';const link=doc.createElement('a');link.href=root.dataset.login;link.className='btn btn-primary mt-3';link.textContent='Sign In';root.querySelector('[data-display-boards]').append(link);return;}
   if(response.status===304){status.className='small text-secondary mt-1';status.textContent=healthyStatus;return;}
   if(!response.ok||response.redirected)throw new Error('Display unavailable');const payload=await response.json();renderVivaBoards(root,payload.boards);etag=response.headers.get('ETag')||'';status.className='small text-secondary mt-1';healthyStatus=`Updated ${payload.generated_at}`;status.textContent=healthyStatus;
  }catch(error){if(!stopped){status.className='text-danger small mt-1';status.textContent='Connection interrupted. Information may be outdated. Retrying automatically…';}}
  finally{clearTimeout(timeout);running=false;if(!stopped&&autoStart)timer=setTimeout(refresh,5000);}
 }
 const onVisibility=()=>{if(!doc.hidden&&!stopped)refresh();};doc.addEventListener('visibilitychange',onVisibility);doc.defaultView?.addEventListener('pagehide',destroy,{once:true});if(autoStart)refresh();return{refresh,destroy};
}
document.querySelectorAll('[data-viva-display]').forEach(root=>enhanceVivaDisplay(root));
