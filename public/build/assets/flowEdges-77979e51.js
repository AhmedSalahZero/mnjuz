function a(u,l,n){return!Array.isArray(u)||l==null||n==null?[]:u.filter(r=>r&&r.source===l&&r.sourceHandle===n).map(r=>r.id)}export{a as e};
