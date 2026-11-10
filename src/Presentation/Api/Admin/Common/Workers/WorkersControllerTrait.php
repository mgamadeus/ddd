<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers;

use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerMessageRunsService;
use DDD\Domain\Common\Services\WorkerProcessesService;
use DDD\Domain\Common\Services\WorkersOverviewService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerMessageRunsGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerMessageRunsGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerProcessesGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerProcessesGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkersHistoryGetRequestDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkersHistoryGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkersOverviewGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkersRestartRequestDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkersRestartResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerTransportDetailGetResponseDto;
use DDD\Presentation\Api\Admin\Common\Workers\Dtos\WorkerTransportGetRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The workers endpoints of an admin API: overview, one transport, history, message runs, processes, restart request.
 * An application mounts them with one controller of its own, which sets the path prefix, the tag and the access:
 *
 *     #[Route('/maintenance/workers')]
 *     #[Tag(group: 'Maintenance', name: 'Workers', description: 'Messenger workers')]
 *     class AdminWorkersController extends AdminController
 *     {
 *         use WorkersControllerTrait;
 *     }
 *
 * The monitoring's tables are readable by admin accounts; the data comes from {@see WorkersOverviewService}.
 */
trait WorkersControllerTrait
{
    /**
     * Every transport with its definition, queue, processes, state and the last hour; the totals; the live processes;
     * the loops.
     */
    #[Get('/overview')]
    #[Summary('Workers Overview')]
    public function getWorkersOverview(WorkersOverviewService $workersOverviewService): WorkersOverviewGetResponseDto
    {
        $responseDto = new WorkersOverviewGetResponseDto();
        $responseDto->workersOverview = $workersOverviewService->getWorkersOverview();
        return $responseDto;
    }

    /**
     * The processes, process starts, queue and throughput of every transport in a window, and of all together.
     *
     * @throws BadRequestException When the window or the step is not valid
     */
    #[Get('/history')]
    #[Summary('Workers History')]
    public function getWorkersHistory(
        WorkersHistoryGetRequestDto $requestDto,
        WorkersOverviewService $workersOverviewService
    ): WorkersHistoryGetResponseDto {
        $responseDto = new WorkersHistoryGetResponseDto();
        $responseDto->workersHistory = $workersOverviewService->getWorkersHistory(
            $requestDto->getFromDateTime(),
            $requestDto->getToDateTime(),
            $requestDto->stepSeconds
        );
        return $responseDto;
    }

    /**
     * Asks the live worker processes (of one transport, or all) to stop after their current message; whatever runs
     * the workers starts them again.
     *
     * @throws BadRequestException When the monitoring is switched off: the processes are not known
     */
    #[Post('/restart')]
    #[Summary('Workers Restart Request')]
    public function requestWorkersRestart(
        WorkersRestartRequestDto $requestDto,
        WorkerProcessesService $workerProcessesService
    ): WorkersRestartResponseDto {
        $workersSettings = WorkersSettings::fromEnvironment();
        if (!$workersSettings->enabled) {
            throw new BadRequestException('The worker monitoring is switched off: the worker processes are not known and cannot be asked to restart');
        }
        $transport = $requestDto->transport !== null && $requestDto->transport !== '' ? $requestDto->transport : null;
        $responseDto = new WorkersRestartResponseDto();
        $responseDto->workerProcesses = $workerProcessesService->requestRestartOfWorkerProcesses(
            DateTime::fromTimestamp(time()),
            $workersSettings->getLostAfterSeconds(),
            $workersSettings->busySilentSeconds,
            $transport
        );
        $responseDto->requestedProcessCount = $responseDto->workerProcesses->count();
        return $responseDto;
    }

    /**
     * One transport in a window: every sequence, its message classes, its live processes.
     *
     * @throws NotFoundException When neither the Messenger configuration nor the worker list knows the transport
     * @throws BadRequestException When the window or the step is not valid
     */
    #[Get('/transports/{transport}')]
    #[Summary('Worker Transport Details')]
    public function getWorkerTransportDetail(
        WorkerTransportGetRequestDto $requestDto,
        WorkersOverviewService $workersOverviewService
    ): WorkerTransportDetailGetResponseDto {
        $responseDto = new WorkerTransportDetailGetResponseDto();
        $responseDto->workerTransportDetail = $workersOverviewService->getWorkerTransportDetail(
            $requestDto->transport,
            $requestDto->getFromDateTime(),
            $requestDto->getToDateTime(),
            $requestDto->stepSeconds
        );
        return $responseDto;
    }

    /**
     * The message runs of a transport, newest first unless ordered otherwise (QueryOptions: filters, orderBy, top, skip).
     */
    #[Get('/transports/{transport}/messageRuns')]
    #[Summary('Worker Transport MessageRuns List')]
    public function getWorkerMessageRuns(
        WorkerMessageRunsGetRequestDto &$requestDto,
        WorkerMessageRunsService $workerMessageRunsService
    ): WorkerMessageRunsGetResponseDto {
        WorkerMessageRuns::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        if (!isset($requestDto->orderBy)) {
            $newestFirst = OrderByOptions::fromString('startedAt desc');
            WorkerMessageRuns::getDefaultQueryOptions()->setOrderBy($newestFirst);
        }
        $responseDto = new WorkerMessageRunsGetResponseDto();
        $responseDto->workerMessageRuns = $workerMessageRunsService->findWorkerMessageRunsOfTransport($requestDto->transport);
        return $responseDto;
    }

    /**
     * The worker processes of a transport — live, stopped and lost —, newest first unless ordered otherwise
     * (QueryOptions: filters, orderBy, top, skip). Each carries its display state.
     */
    #[Get('/transports/{transport}/processes')]
    #[Summary('Worker Transport Processes List')]
    public function getWorkerProcesses(
        WorkerProcessesGetRequestDto &$requestDto,
        WorkerProcessesService $workerProcessesService
    ): WorkerProcessesGetResponseDto {
        WorkerProcesses::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        if (!isset($requestDto->orderBy)) {
            $newestFirst = OrderByOptions::fromString('startedAt desc');
            WorkerProcesses::getDefaultQueryOptions()->setOrderBy($newestFirst);
        }
        $workersSettings = WorkersSettings::fromEnvironment();
        $now = DateTime::fromTimestamp(time());
        $responseDto = new WorkerProcessesGetResponseDto();
        $responseDto->workerProcesses = $workerProcessesService->findWorkerProcessesOfTransport($requestDto->transport);
        $workerProcessesService->setDisplayStates($responseDto->workerProcesses, $now, $workersSettings->getLostAfterSeconds());
        return $responseDto;
    }
}
