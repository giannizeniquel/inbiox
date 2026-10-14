import { of, throwError } from 'rxjs';
import { ManageAgentUserLinkingUseCase, LinkUserInput, UnlinkUserInput, AgentLinkingFailed } from './manage-agent-user-linking.use-case';
import { AdminApiService, LinkUserResponse } from './admin-api.service';

describe('ManageAgentUserLinkingUseCase', () => {
  let useCase: ManageAgentUserLinkingUseCase;
  let apiSpy: jasmine.SpyObj<AdminApiService>;

  const linkResponse: LinkUserResponse = {
    id: 1,
    user_id: 10,
    user_email: 'user@example.com',
  };

  beforeEach(() => {
    apiSpy = jasmine.createSpyObj('AdminApiService', ['linkAgentToUser', 'unlinkAgentFromUser']);
    useCase = new ManageAgentUserLinkingUseCase(apiSpy);
  });

  describe('link()', () => {
    const input: LinkUserInput = { agentId: 5, userId: 10 };

    it('should call api.linkAgentToUser with agentId and user_id', (done) => {
      apiSpy.linkAgentToUser.and.returnValue(of(linkResponse));
      useCase.link(input).subscribe({
        next: () => {
          expect(apiSpy.linkAgentToUser).toHaveBeenCalledWith(5, { user_id: 10 });
          done();
        },
      });
    });

    it('should return the LinkUserResponse on success', (done) => {
      apiSpy.linkAgentToUser.and.returnValue(of(linkResponse));
      useCase.link(input).subscribe({
        next: (res) => {
          expect(res).toEqual(linkResponse);
          done();
        },
      });
    });

    it('should throw AgentLinkingFailed when api.linkAgentToUser errors', (done) => {
      apiSpy.linkAgentToUser.and.returnValue(throwError(() => new Error('Link conflict')));
      useCase.link(input).subscribe({
        error: (err: AgentLinkingFailed) => {
          expect(err).toBeInstanceOf(AgentLinkingFailed);
          expect(err.message).toBe('Link conflict');
          done();
        },
      });
    });
  });

  describe('unlink()', () => {
    const input: UnlinkUserInput = { agentId: 5 };

    it('should call api.unlinkAgentFromUser with the agentId', (done) => {
      apiSpy.unlinkAgentFromUser.and.returnValue(of(undefined));
      useCase.unlink(input).subscribe({
        next: () => {
          expect(apiSpy.unlinkAgentFromUser).toHaveBeenCalledWith(5);
          done();
        },
      });
    });

    it('should return void on success', (done) => {
      apiSpy.unlinkAgentFromUser.and.returnValue(of(undefined));
      useCase.unlink(input).subscribe({
        next: (res) => {
          expect(res).toBeUndefined();
          done();
        },
      });
    });

    it('should throw AgentLinkingFailed when api.unlinkAgentFromUser errors', (done) => {
      apiSpy.unlinkAgentFromUser.and.returnValue(throwError(() => new Error('Not linked')));
      useCase.unlink(input).subscribe({
        error: (err: AgentLinkingFailed) => {
          expect(err).toBeInstanceOf(AgentLinkingFailed);
          expect(err.message).toBe('Not linked');
          done();
        },
      });
    });
  });
});
