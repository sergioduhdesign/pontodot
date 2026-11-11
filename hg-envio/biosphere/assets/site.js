(function(){
var t=document.getElementById('top'),b=document.getElementById('bg'),n=document.getElementById('nav');
function sc(){t.classList.toggle('sc',window.scrollY>10)}sc();window.addEventListener('scroll',sc,{passive:true});
if(b){b.addEventListener('click',function(){var o=n.classList.toggle('on');b.setAttribute('aria-expanded',o)});n.addEventListener('click',function(e){if(e.target.tagName==='A'){n.classList.remove('on');b.setAttribute('aria-expanded','false')}})}
var rv=document.querySelectorAll('.rv');
if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}})},{rootMargin:'0px 0px -8% 0px',threshold:.05});rv.forEach(function(x){io.observe(x)})}else rv.forEach(function(x){x.classList.add('in')});
/* lightbox */
var lb=document.getElementById('lb');
if(lb){var li=lb.querySelector('img'),lp=lb.querySelector('p');
function fecha(){lb.hidden=true;li.removeAttribute('src');document.body.style.overflow=''}
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-lb]');if(a){e.preventDefault();li.src=a.href;var f=a.parentNode.querySelector('figcaption');lp.textContent=f?f.textContent:'';lb.hidden=false;document.body.style.overflow='hidden';return}
if(!lb.hidden&&(e.target===lb||e.target.className==='lb-x'))fecha()});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!lb.hidden)fecha()})}
/* formulário */
var f=document.getElementById('fm');
if(f){f.addEventListener('submit',function(e){e.preventDefault();var s=document.getElementById('fs'),bt=f.querySelector('button');s.className='fm-st';s.textContent='Enviando…';bt.disabled=true;
fetch(f.action,{method:'POST',body:new FormData(f)}).then(function(r){return r.json().catch(function(){return{ok:false,msg:'Erro ao enviar.'}})}).then(function(j){s.textContent=j.msg;s.className='fm-st '+(j.ok?'ok':'er');bt.disabled=false;if(j.ok)f.reset()}).catch(function(){s.textContent='Sem conexão. Tente novamente.';s.className='fm-st er';bt.disabled=false})})}
})();
