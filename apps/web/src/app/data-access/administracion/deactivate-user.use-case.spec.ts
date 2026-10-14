import { of, throwError } from 'rxjs';
import { DeactivateUserUseCase, DeactivateUserInput, DeactivateUserOutput, UserDeactivationFailed } from './deactivate-user.use-case';
import { AdminApiService } from './admin-api.service';

describe('DeactivateUserUseCase', () => {
  let useCase: DeactivateUserUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['deleteUser']);
    useCase = new DeactivateUserUseCase(apiSpy);
  });

  it('should call api.deleteUser with the user id', (done) => {
    apiSpy.deleteUser.and.returnValue(of(undefined));
    useCase.execute({ id: 8 }).subscribe({
      next: () => {
        expect(apiSpy.deleteUser).toHaveBeenCalledWith(8);
        done();
      },
    });
  });

  it('should return DeactivateUserOutput with the deactivated user id', (done) => {
    apiSpy.deleteUser.and.returnValue(of(undefined));
    useCase.execute({ id: 8 }).subscribe({
      next: (output: DeactivateUserOutput) => {
        expect(output.id).toBe(8);
        done();
      },
    });
  });

  it('should throw UserDeactivationFailed when api.deleteUser errors', (done) => {
    apiSpy.deleteUser.and.returnValue(throwError(() => new Error('Network error')));
    useCase.execute({ id: 8 }).subscribe({
      error: (err: UserDeactivationFailed) => {
        expect(err).toBeInstanceOf(UserDeactivationFailed);
        expect(err.message).toBe('Network error');
        done();
      },
    });
  });
});
