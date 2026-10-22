import { describe,it,expect } from 'vitest'
import { minor,approvalLevel } from '../commercial'
describe('Commercial precision',()=>{
 it.each([['1000',100000],['0,01',1],['234.50',23450],['10000000000',1000000000000]])('parses %s exactly',(input,expected)=>expect(minor(input)).toBe(expected))
 it.each(['-1','1e6','Infinity','1.123','1.000.000','', '10000000001'])('rejects invalid input %s',value=>expect(()=>minor(value)).toThrow())
 it.each([[8500,0],[8501,1],[9000,1],[9001,2],[9500,2],[9501,3],[12000,3]])('classifies cost %s at the exact approval boundary',(cost,tier)=>expect(approvalLevel(10000,cost,1500)).toBe(tier))
 it('honors stage-specific thresholds',()=>expect(approvalLevel(10000,8000,2500)).toBe(1))
})
