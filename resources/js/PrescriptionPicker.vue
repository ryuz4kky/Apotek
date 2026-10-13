<script setup>
import {ref,watch,onBeforeUnmount} from 'vue';
import {FileText,Search,X,ChevronDown} from 'lucide-vue-next';
const props=defineProps({selected:Object,hasCart:Boolean});
const emit=defineEmits(['select','clear']);
const expanded=ref(false);const query=ref('');const results=ref([]);const loading=ref(false);const error=ref('');
const labels={received:'Menunggu telaah',reviewed:'Sudah ditelaah',prepared:'Menunggu pemeriksaan',checked:'Siap di kasir'};
let timer;let request;
watch([expanded,query],([open,value])=>{
 clearTimeout(timer);request?.abort();loading.value=false;error.value='';
 if(!open)return;
 loading.value=true;
 timer=setTimeout(async()=>{
  const current=new AbortController();request=current;
  try{const response=await fetch('/lookup/prescriptions?q='+encodeURIComponent(value),{headers:{Accept:'application/json'},signal:current.signal});if(!response.ok)throw new Error('lookup');const data=await response.json();if(!current.signal.aborted)results.value=data;}
  catch(e){if(e.name!=='AbortError')error.value='Daftar resep gagal dimuat. Coba lagi atau masuk ulang.';}
  finally{if(!current.signal.aborted)loading.value=false;}
 },200);
});
onBeforeUnmount(()=>{clearTimeout(timer);request?.abort();});
const remaining=rx=>rx.items.filter(item=>Number(item.remaining_base)>0);
const allowed=rx=>rx.ready_for_checkout&&remaining(rx).length>0&&remaining(rx).every(item=>item.active&&!item.special_control&&item.units.some(unit=>unit.name===item.base_unit&&Number(unit.factor)===1));
function select(rx){emit('select',rx);expanded.value=false;query.value='';}
</script>
<template><section class="prescription-picker"><div class="rx-picker-heading"><FileText :size="17"/><strong>Transaksi resep</strong></div><template v-if="selected"><div class="rx-selected"><div><strong>{{selected.number}}</strong><p class="rx-person">{{selected.patient_name}}</p><small>{{selected.doctor}}</small><span class="status" :class="selected.status">{{labels[selected.status]}}</span></div><button type="button" @click="emit('clear')" title="Hapus resep dan item dari keranjang" aria-label="Hapus resep dan item"><X :size="16"/></button></div><p class="item-help">Resep sudah diperiksa apoteker. Bayar & selesaikan akan langsung memperbarui stok dan menyelesaikan transaksi.</p></template><button type="button" class="secondary rx-open" @click="expanded=!expanded"><Search :size="16"/>{{selected?'Ganti resep':'Cari / pilih resep'}}<ChevronDown :size="15"/></button><div v-if="expanded" class="rx-results"><label>Cari resep, pasien, atau dokter<input v-model="query" placeholder="Nomor resep, nama pasien, atau dokter" autocomplete="off"/></label><p v-if="hasCart" class="item-help">Memilih resep akan mengganti isi keranjang dengan sisa obat dari resep tersebut.</p><p v-if="loading" class="item-help" role="status">Memuat daftar resep…</p><div v-else-if="error" class="error">{{error}}</div><p v-else-if="!results.length" class="item-help">Tidak ada resep yang cocok. Resep dicatat lebih dulu oleh apoteker.</p><div v-else v-for="rx in results" :key="rx.id" class="rx-result"><div><strong>{{rx.number}}</strong><p class="rx-person">{{rx.patient_name}}</p><small>{{rx.doctor}} · {{rx.date}}</small><span class="status" :class="rx.status">{{labels[rx.status]}}</span></div><ul><li v-for="item in rx.items" :key="item.product_id">{{item.name}} <small>· sisa {{Number(item.remaining_base)}} {{item.base_unit}}</small></li></ul><p v-if="!remaining(rx).length" class="item-help">Semua item resep sudah masuk transaksi pembayaran.</p><p v-else-if="!rx.ready_for_checkout" class="item-help">Resep belum siap di kasir. Selesaikan telaah, penyiapan, dan pemeriksaan pada akun apoteker terlebih dahulu.</p><p v-else-if="!allowed(rx)" class="item-help">Ada produk tidak aktif, pengawasan khusus, atau satuan yang belum tersedia.</p><button class="secondary" type="button" :disabled="!allowed(rx)" @click="select(rx)" :aria-label="'Pilih resep '+rx.number">{{hasCart?'Ganti keranjang dengan resep':'Pilih resep & isi keranjang'}}</button></div></div></section></template>
