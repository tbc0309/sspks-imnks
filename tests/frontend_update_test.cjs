'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname,'../themes/material/js/update.js'),'utf8');
async function scenario(responses, expected, calls, saved) {
 const statuses=[]; const status={set textContent(v){statuses.push(v)},get textContent(){return statuses.at(-1)}};
 const input={value:'incorrect-test-password'};const counters={};const buttons=[{disabled:false},{disabled:false}];
 const list=()=>({children:[],textContent:'',appendChild(){}});const success=list(),failure=list();const progress={value:0},percent={textContent:''};let submit,requests=0;
 const form={querySelectorAll:()=>buttons,addEventListener:(event,callback)=>{submit=callback}};
 const nodes={'[data-update-form]':form,'[data-update-token]':input,'[data-update-status]':status,'[data-update-percent]':percent,'[data-update-progress]':progress,'[data-success-list]':success,'[data-failure-list]':failure};
 const panel={querySelector(selector){return nodes[selector]||(counters[selector]||=( {hidden:false,textContent:'0'}))},getAttribute:()=>'/test-update',setAttribute(){},classList:{contains:()=>false,add(){},remove(){}}};
 const context={window:{SSPKS_I18N:{}},document:{readyState:'complete',querySelector:()=>panel},URLSearchParams,AbortController,console,setTimeout(callback){queueMicrotask(callback);return 1},clearTimeout(){},fetch:async()=>{
  const result=responses[Math.min(requests++,responses.length-1)];if(result instanceof Error)throw result;
  return {status:result.status,ok:result.status>=200&&result.status<300,json:async()=>{if(result.html)throw new SyntaxError('HTML error page');return result.data}};
 }};
 vm.runInNewContext(source,context);await submit({preventDefault(){},submitter:{value:'incremental'}});
 assert.strictEqual(requests,calls,'Wrong retry count');assert.match(status.textContent,expected);
 assert.strictEqual(statuses.some(s=>/saved|已保存|进度已保存/.test(s)),saved,'Misleading saved progress');
 assert.strictEqual(input.value,'');assert(buttons.every(b=>!b.disabled));
}
(async()=>{
 await scenario([{status:401,html:true}],/password|密码/,1,false);
 await scenario([{status:403,html:true}],/password|密码/,1,false);
 await scenario([{status:429,html:true}],/minute|一分钟/,1,false);
 await scenario([{status:404,html:true}],/endpoint|入口/,1,false);
 await scenario([{status:200,html:true}],/invalid update|更新响应无效/,1,false);
 await scenario([{status:503,data:{type:'error',code:'auth_unavailable'}}],/verification|校验/,1,false);
 await scenario([{status:500,html:true}],/No update task|尚未确认/,4,false);
 await scenario([{status:200,data:{type:'progress',job:'test-job',percent:0,phase:'scan',mode:'incremental',events:[],total:1}},{status:500,html:true}],/saved|进度已保存/,5,true);
 console.log('Frontend authentication tests passed: HTML proxy errors, no auth retries, rate limits, invalid response and truthful resume state.');
})().catch(error=>{console.error(error);process.exitCode=1});
