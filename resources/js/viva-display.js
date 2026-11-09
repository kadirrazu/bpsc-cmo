export const memberDetails={member_number:'Member Number',floor:'Floor',room_number:'Room Number',lift_button_number:'Lift Button Number',name_of_po:'Name of PO'};
export function renderVivaBoards(root,boards){
 const doc=root.ownerDocument,list=root.querySelector('[data-display-boards]');list.replaceChildren();
 const el=(tag,css,text)=>{const n=doc.createElement(tag);n.className=css;if(text!==undefined)n.textContent=String(text);return n;};
 if(!boards.length){list.append(el('div','viva-display-empty','No boards have been assigned for this date yet. This screen updates automatically.'));return;}
 boards.forEach(board=>{const card=el('article','viva-display-board'),header=el('div','viva-board-banner'),body=el('div','viva-board-body');header.append(el('span','',`Post ${board.post_code}`),el('strong','',`Board ${board.board}`));body.append(el('h2','',board.post_name),el('h3','',board.member.name));const details=el('div','viva-room-details');Object.entries(memberDetails).forEach(([key,label])=>{if([null,undefined,''].includes(board.member[key]))return;const item=el('span','');item.append(el('small','',label),el('strong','',board.member[key]));details.append(item);});const title=el('h4','','Registration Numbers ');title.append(el('span','badge bg-teal-lt',board.candidate_count));const regs=el('div','viva-registration-grid');board.registrations.forEach(reg=>regs.append(el('span','',reg)));body.append(details,title,regs);card.append(header,body);list.append(card);});
}
export function enhanceVivaDisplay(root,autoStart=true){
 const doc=root.ownerDocument,status=root.querySelector('[data-display-status]');let healthyStatus=status.textContent,etag='',timer,controller,stopped=false,running=false;
 const destroy=()=>{stopped=true;clearTimeout(timer);controller?.abort();};
 async function refresh(){
  if(stopped||running)return;clearTimeout(timer);running=true;controller=new AbortController;const timeout=setTimeout(()=>controller.abort(),10000);
  try{
   if(root.dataset.followToday==='true'){const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Dhaka',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date);if(today!==root.dataset.date){root.dataset.date=today;etag='';const input=doc.querySelector('#screen-date');if(input)input.value=today;}}
   const url=new URL(root.dataset.url,doc.baseURI);url.searchParams.set('vdate',root.dataset.date);
   const response=await fetch(url,{headers:{Accept:'application/json',...(etag?{'If-None-Match':etag}:{})},credentials:'same-origin',cache:'no-store',signal:controller.signal});
   if([401,419].includes(response.status)){destroy();root.querySelector('[data-display-boards]').replaceChildren();status.textContent='Display session expired. Sign in again.';const link=doc.createElement('a');link.href=root.dataset.login;link.className='btn btn-primary mt-3';link.textContent='Sign In';root.append(link);return;}
   if(response.status===304){status.className='small text-secondary mt-1';status.textContent=healthyStatus;return;}
   if(!response.ok||response.redirected)throw new Error('Display unavailable');const payload=await response.json();renderVivaBoards(root,payload.boards);etag=response.headers.get('ETag')||'';status.className='small text-secondary mt-1';healthyStatus=`Updated ${payload.generated_at}`;status.textContent=healthyStatus;
  }catch(error){if(!stopped){status.className='alert alert-warning mb-0';status.textContent='Connection interrupted. Displayed information may be outdated. Retrying automatically…';}}
  finally{clearTimeout(timeout);running=false;if(!stopped&&autoStart)timer=setTimeout(refresh,5000);}
 }
 doc.addEventListener('visibilitychange',()=>{if(!doc.hidden&&!stopped)refresh();});doc.defaultView?.addEventListener('pagehide',destroy,{once:true});if(autoStart)refresh();return{refresh,destroy};
}
document.querySelectorAll('[data-viva-display]').forEach(root=>enhanceVivaDisplay(root));
