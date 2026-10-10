/* ISABEL UNIFIED v2 — shared Anthropic key, browser auth and current models (generated into every tool by inject.py; edit tools-interceptor.js, not the tools) */
(function(){
  var KEY='isabel_anthropic_key', CHAT='isabel_chat_model';
  var FALLBACKS=['claude-sonnet-5-5','claude-sonnet-5','claude-sonnet-4-6','claude-haiku-5-5'];
  var ORIGIN=(location.origin&&location.origin!=='null')?location.origin:'*';
  function ls(k){try{return localStorage.getItem(k)||''}catch(e){return ''}}
  var _k=ls(KEY), _m=ls(CHAT)||FALLBACKS[0];
  window.addEventListener('message',function(e){
    if(e.source!==window.parent) return;
    if(ORIGIN!=='*'&&e.origin!==ORIGIN&&e.origin!=='null') return;
    var d=e.data; if(!d||d.type!=='ISABEL_API_KEY') return;
    _k=d.key||''; try{localStorage.setItem(KEY,_k)}catch(_){}
    if(d.chatModel){_m=d.chatModel; try{localStorage.setItem(CHAT,_m)}catch(_){}}
  });
  try{if(window.parent&&window.parent!==window)window.parent.postMessage({type:'ISABEL_REQUEST_KEY'},ORIGIN);}catch(_){}
  function friendly(status,type,msg){
    msg=String(msg||'');
    if(/credit balance|billing/i.test(msg)) return 'Tu cuenta de Anthropic no tiene saldo. Agrégalo en console.anthropic.com → Plans & Billing.';
    if(status===401||type==='authentication_error') return 'Tu API Key no es válida. Revísala arriba a la derecha (empieza con sk-ant-).';
    if(status===429||type==='rate_limit_error') return 'Hay demasiadas solicitudes a la vez. Espera un minuto e intenta de nuevo.';
    if(status===529||status>=500||type==='overloaded_error') return 'La IA está muy ocupada. Intenta de nuevo en unos segundos.';
    return msg;
  }
  function isModelErr(status,d){
    var e=(d&&d.error)||{}, m=e.message||'';
    return status===404||e.type==='not_found_error'||(/model/i.test(m)&&/not (found|valid)|invalid|retired|deprecated|does not exist/i.test(m));
  }
  function json(res,d){
    return new Response(JSON.stringify(d),{status:res.status,statusText:res.statusText,headers:{'Content-Type':'application/json'}});
  }
  if(!window.__isabelFetchPatched){
    window.__isabelFetchPatched=true;
    var of=window.fetch.bind(window);
    window.fetch=function(u,o){
      var url=(typeof u==='string')?u:((u&&u.url)||'');
      if(url.indexOf('api.anthropic.com')<0) return of(u,o);
      o=o||{};
      var body=null;
      try{body=(typeof o.body==='string')?JSON.parse(o.body):null;}catch(e){body=null;}
      var h=Object.assign({},o.headers||{});
      h['x-api-key']=_k; h['anthropic-version']='2023-06-01'; h['anthropic-dangerous-direct-browser-access']='true';
      var tried=[], model=_m;
      function send(){
        var o2=Object.assign({},o,{headers:h});
        if(body){
          var b=Object.assign({},body);
          b.model=model;
          if(!b.max_tokens||b.max_tokens<4096) b.max_tokens=4096;   /* el límite incluye el "pensar" del modelo */
          if(!b.output_config) b.output_config={effort:'medium'};
          delete b.temperature; delete b.top_p; delete b.top_k;
          o2.body=JSON.stringify(b);
        }
        tried.push(model);
        return of(u,o2);
      }
      function attempt(){
        return send().then(function(res){
          if(res.ok){
            if((res.headers.get('content-type')||'').indexOf('application/json')<0) return res;
            return res.clone().json().then(function(d){
              if(d&&Array.isArray(d.content)) d.content=d.content.filter(function(x){return x&&x.type==='text';});
              return json(res,d);
            }).catch(function(){return res;});
          }
          return res.clone().json().then(function(d){
            if(isModelErr(res.status,d)){
              var next=FALLBACKS.filter(function(m){return tried.indexOf(m)<0;})[0];
              if(next){model=next; return attempt();}
            }
            if(d&&d.error) d.error.message=friendly(res.status,d.error.type,d.error.message);
            return json(res,d);
          }).catch(function(){return res;});
        });
      }
      return attempt();
    };
  }
})();
