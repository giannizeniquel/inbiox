import { BuildTimeFilterParamsUseCase, InvalidTimeFilter } from './build-time-filter-params.use-case';
import { TimeFilter } from './reports.params';

describe('BuildTimeFilterParamsUseCase', () => {
  let useCase: BuildTimeFilterParamsUseCase;

  beforeEach(() => {
    useCase = new BuildTimeFilterParamsUseCase();
  });

  const toDateString = (d: Date): string => d.toISOString().split('T')[0];

  describe('today filter', () => {
    it('should return same fromDate and toDate as today', () => {
      const today = new Date();
      const result = useCase.build('today', '', '');
      expect(result.fromDate).toBe(toDateString(today));
      expect(result.toDate).toBe(toDateString(today));
    });
  });

  describe('7d filter', () => {
    it('should return toDate as today and fromDate 7 days prior', () => {
      const today = new Date();
      const sevenDaysAgo = new Date(today);
      sevenDaysAgo.setDate(today.getDate() - 7);

      const result = useCase.build('7d', '', '');
      expect(result.fromDate).toBe(toDateString(sevenDaysAgo));
      expect(result.toDate).toBe(toDateString(today));
    });
  });

  describe('30d filter', () => {
    it('should return toDate as today and fromDate 30 days prior', () => {
      const today = new Date();
      const thirtyDaysAgo = new Date(today);
      thirtyDaysAgo.setDate(today.getDate() - 30);

      const result = useCase.build('30d', '', '');
      expect(result.fromDate).toBe(toDateString(thirtyDaysAgo));
      expect(result.toDate).toBe(toDateString(today));
    });
  });

  describe('custom filter', () => {
    it('should return the provided fromDate and toDate when both are valid', () => {
      const result = useCase.build('custom', '2024-01-01', '2024-01-31');
      expect(result.fromDate).toBe('2024-01-01');
      expect(result.toDate).toBe('2024-01-31');
    });

    it('should throw InvalidTimeFilter when fromDate is empty', () => {
      try {
        useCase.build('custom', '', '2024-01-31');
        fail('Expected InvalidTimeFilter to be thrown');
      } catch (err: unknown) {
        expect((err as InvalidTimeFilter).name).toBe('InvalidTimeFilter');
      }
    });

    it('should throw InvalidTimeFilter when toDate is empty', () => {
      try {
        useCase.build('custom', '2024-01-01', '');
        fail('Expected InvalidTimeFilter to be thrown');
      } catch (err: unknown) {
        expect((err as InvalidTimeFilter).name).toBe('InvalidTimeFilter');
      }
    });

    it('should throw InvalidTimeFilter when fromDate is after toDate', () => {
      try {
        useCase.build('custom', '2024-02-01', '2024-01-01');
        fail('Expected InvalidTimeFilter to be thrown');
      } catch (err: unknown) {
        expect((err as InvalidTimeFilter).name).toBe('InvalidTimeFilter');
      }
    });

    it('should not throw when fromDate equals toDate', () => {
      expect(() => useCase.build('custom', '2024-01-15', '2024-01-15')).not.toThrow();
    });
  });

  describe('unknown filter', () => {
    it('should throw InvalidTimeFilter for unknown filter value', () => {
      try {
        useCase.build('unknown' as TimeFilter, '', '');
        fail('Expected InvalidTimeFilter to be thrown');
      } catch (err: unknown) {
        expect((err as InvalidTimeFilter).name).toBe('InvalidTimeFilter');
      }
    });
  });
});
