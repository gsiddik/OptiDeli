import { describe,it,expect,vi,afterEach } from 'vitest'
import { render,screen,fireEvent,waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import App from '../App'
import { Form } from '../components'
import { ApiError } from '../api'
const session={id:1,name:'Dispatcher',role:'dispatcher',tenant:{name:'Tenant A',slug:'a',minimum_margin_bps:1500,stage_margin_bps:{}},deployment_mode:'saas'}
afterEach(()=>vi.unstubAllGlobals())
function respond(path:string){if(path.includes('auth/me'))return {data:session};if(path.includes('dashboard'))return {data:{orders:[{status:'DRAFT',total:4}],approval_pending:2,fleet_available:3,outbox_pending:9}};return {data:[],current_page:1,last_page:1,total:0}}
describe('Application shell and server states',()=>{
 it('shows real dashboard counts, persists collapsed navigation and has keyboard labels',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(path:string)=>new Response(JSON.stringify(respond(path)),{status:200})))
  render(<MemoryRouter><App/></MemoryRouter>);await screen.findByRole('heading',{name:'Ringkasan transportasi'})
  expect(await screen.findByText('4')).toBeTruthy();expect(screen.getByText('2')).toBeTruthy()
  fireEvent.click(screen.getByRole('button',{name:'Perkecil sidebar'}));expect(localStorage.getItem('optideli.sidebar')).toBe('compact')
  expect(screen.getByRole('button',{name:'Perluas sidebar'}).getAttribute('aria-expanded')).toBe('false')
  fireEvent.click(screen.getByRole('link',{name:'Pemesanan'}));await screen.findByRole('heading',{name:'Pesanan transportasi'})
  expect(screen.getByRole('navigation',{name:'Breadcrumb'})).toBeTruthy();expect(screen.getByRole('button',{name:'Kembali'})).toBeTruthy()
 })
 it('shows login when the session is not authenticated',async()=>{
  vi.stubGlobal('fetch',vi.fn(async()=>new Response(JSON.stringify({message:'Unauthenticated.'}),{status:401})))
  render(<MemoryRouter><App/></MemoryRouter>);await screen.findByRole('button',{name:'Masuk'});expect(screen.getByLabelText('Kata sandi').getAttribute('type')).toBe('password')
 })
 it('shows an actionable backend error instead of fake dashboard data',async()=>{
  vi.stubGlobal('fetch',vi.fn(async()=>new Response(JSON.stringify({message:'Service unavailable'}),{status:503})))
  render(<MemoryRouter><App/></MemoryRouter>);await screen.findByRole('alert');expect(screen.queryByRole('heading',{name:'Ringkasan transportasi'})).toBeNull();expect(screen.getByRole('button',{name:'Coba lagi'})).toBeTruthy()
 })
 it('keeps submission errors visible and reenables the form for retry',async()=>{
  const submit=vi.fn().mockRejectedValue(new ApiError('Dispatch terkunci',422,{reason_code:['Alasan wajib']}))
  render(<Form onSubmit={submit}><input aria-label="Nominal"/></Form>);fireEvent.click(screen.getByRole('button',{name:'Simpan'}));await screen.findByRole('alert');expect(screen.getByText('Alasan wajib')).toBeTruthy();await waitFor(()=>expect(screen.getByRole('button',{name:'Simpan'}).hasAttribute('disabled')).toBe(false));expect(submit).toHaveBeenCalledTimes(1)
 })
})
